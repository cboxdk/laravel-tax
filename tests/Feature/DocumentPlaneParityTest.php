<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Catalogue\ArrayProductCatalogue;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\ProductCatalogue;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * The order plane adds no tax logic — `queryFor()` turns each line into a single
 * supply so every gate applies identically. Three fields fell out of that
 * conversion and the promise quietly stopped holding, which is exactly the kind of
 * divergence a document plane accumulates when nothing pins it.
 */
beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
});

it('applies the postcode gate to a document, as it does to one supply', function () {
    // Tenerife sits inside Spain and outside its VAT area. Without the postcode a
    // two-line invoice was charged 21% mainland VAT while the identical single
    // supply correctly treated it as an export.
    $place = $this->geo->find(new CountryCode('ES'));
    $seller = new SellerRegistrations(new CountryCode('ES'));

    $single = $this->app->make(TaxCalculator::class)->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $place,
        customer: CustomerType::Consumer,
        seller: $seller,
        postalCode: '38001',
    ));

    $document = $this->app->make(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        place: $place,
        customer: CustomerType::Consumer,
        seller: $seller,
        pricing: Pricing::Exclusive,
        lines: [new SupplyLine('a', Money::of('100.00', 'EUR'))],
        postalCode: '38001',
    ));

    expect($document->forLine('a')?->treatment)->toBe($single->treatment)
        ->and($document->forLine('a')?->tax->getAmount()->toFloat())
        ->toBe($single->tax->getAmount()->toFloat());
});

it('applies the marketplace gate to a document', function () {
    // Without it, a marketplace invoicing a multi-line order charged tax the
    // marketplace had already collected — a double charge on every such invoice.
    $subdivision = new SubdivisionCode('US-WA');

    $document = $this->app->make(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        place: $this->geo->find(new CountryCode('US'), $subdivision),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(
            new CountryCode('US'),
            [new SellerRegistration(new CountryCode('US'), $subdivision)],
        ),
        pricing: Pricing::Exclusive,
        lines: [new SupplyLine('a', Money::of('100.00', 'USD')), new SupplyLine('b', Money::of('50.00', 'USD'))],
        marketplaceFacilitated: true,
    ));

    expect($document->forLine('a')?->treatment)->toBe(TaxTreatment::MarketplaceFacilitated)
        ->and($document->forLine('b')?->treatment)->toBe(TaxTreatment::MarketplaceFacilitated)
        ->and($document->tax()->getAmount()->toFloat())->toBe(0.0);
});

it('resolves a document line\'s class from the product catalogue', function () {
    // The catalogue was unreachable from a document: assessLine() called
    // assessSupply() directly and skipped classification entirely.
    $this->app->instance(ProductCatalogue::class, new ArrayProductCatalogue([
        'SHOE-001' => TaxClass::Footwear,
    ]));

    $document = $this->app->make(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        place: $this->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
        pricing: Pricing::Exclusive,
        lines: [new SupplyLine('a', Money::of('100.00', 'EUR'), itemCode: 'SHOE-001')],
    ));

    expect($document->forLine('a')?->rate?->limitedBy)->toBeNull();
});

it('flags an unmapped SKU on a document line', function () {
    // The review loop the catalogue exists for reported nothing on the path most
    // invoices actually take.
    $this->app->instance(ProductCatalogue::class, new ArrayProductCatalogue);

    $document = $this->app->make(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        place: $this->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
        pricing: Pricing::Exclusive,
        lines: [new SupplyLine('a', Money::of('100.00', 'EUR'), itemCode: 'NEVER-MAPPED')],
    ));

    expect($document->forLine('a')?->rate?->limitedBy)->toBe(RateLimit::ItemUnmapped);
});

it('carries every field a query has, so the next one cannot fall out unnoticed', function () {
    // The structural guard. `queryFor()` is the one place a line becomes a supply,
    // and three fields were lost by being added to TaxQuery and forgotten here.
    // This fails the moment a fourth is.
    $order = new TaxOrder(
        place: test()->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
        pricing: Pricing::Exclusive,
        lines: [new SupplyLine('a', Money::of('100.00', 'EUR'))],
    );

    $constructed = new ReflectionMethod(TaxOrder::class, 'queryFor');
    $source = (string) file_get_contents((string) $constructed->getFileName());
    $body = substr($source, (int) strpos($source, 'public function queryFor'));
    $body = substr($body, 0, (int) strpos($body, "\n    }"));

    $lost = [];

    foreach (new ReflectionClass(TaxQuery::class)->getConstructor()?->getParameters() ?? [] as $parameter) {
        if (! str_contains($body, $parameter->getName().':')) {
            $lost[] = $parameter->getName();
        }
    }

    // Collected and asserted once, so the failure names EVERY field that fell out
    // rather than stopping at the first — three were lost together, and finding
    // them one build at a time is how the second and third survived.
    expect($lost)->toBe([], 'queryFor() never passes these, so a document silently loses them: '.implode(', ', $lost));

    expect($order->queryFor($order->lines[0]))->toBeInstanceOf(TaxQuery::class);
});
