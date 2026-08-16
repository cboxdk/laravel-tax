<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\ApportionmentBasis;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\EuTaxData\EuTaxDataset;
use Cbox\Tax\Exceptions\InvalidTaxOrder;
use Cbox\Tax\RateSource\EuTaxDatasetRateSource;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxOrder;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

/**
 * Delivery takes the rates of what it delivers — Article 78(b).
 *
 * France is the fixture used throughout because it reduces foodstuffs to 5.5% while
 * charging 20% on everything else, so a two-line cart makes the whole question
 * visible in one order: the identical courier doing the identical work is a 5.5%
 * cost for the groceries and a 20% one for the laptop.
 */
beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);

    $this->app->bind(TaxRateSource::class, fn () => new EuTaxDatasetRateSource(
        new EuTaxDataset(
            app(Factory::class),
            app(Cache::class),
            dirname(__DIR__).'/Fixtures/eu-tax-dataset',
        ),
    ));

    $this->order = function (array $lines, ApportionmentBasis $basis = ApportionmentBasis::NetValue): TaxOrder {
        return new TaxOrder(
            place: test()->geo->find(new CountryCode('FR')),
            customer: CustomerType::Consumer,
            seller: new SellerRegistrations(new CountryCode('FR'), [new SellerRegistration(new CountryCode('FR'))]),
            pricing: Pricing::Exclusive,
            lines: $lines,
            apportionment: $basis,
        );
    };
});

function deliveryLine(string $amount): SupplyLine
{
    return new SupplyLine('shipping', Money::of($amount, 'EUR'), isDeliveryCharge: true);
}

function assessOrder(TaxOrder $order): array
{
    $document = app(OrderTaxCalculator::class)->assessOrder($order);
    $byId = [];

    foreach ($document->lines as $line) {
        $byId[$line->id] = $line->assessment;
    }

    return $byId;
}

it('charges delivery at the rate of the single thing it delivers', function () {
    // The everyday case. Books at 5.5% means the postage is 5.5% too — not 20%,
    // which is what happens today when a caller has to pick a class for it.
    $lines = assessOrder(($this->order)([
        new SupplyLine('food', Money::of('100.00', 'EUR'), TaxClass::Groceries),
        deliveryLine('10.00'),
    ]));

    expect((string) $lines['shipping']->tax->getAmount())->toBe('0.55')
        ->and((string) $lines['shipping']->rate?->percentage)->toBe('5.5');
});

it('splits delivery across a mixed cart by net value', function () {
    // 100 of groceries at 5.5% and 300 of electronics at 20%, so a quarter of the
    // postage rides at the reduced rate: 5.00 × 5.5% + 15.00 × 20% = 0.275 + 3.00.
    $lines = assessOrder(($this->order)([
        new SupplyLine('food', Money::of('100.00', 'EUR'), TaxClass::Groceries),
        new SupplyLine('laptop', Money::of('300.00', 'EUR'), TaxClass::Electronics),
        deliveryLine('20.00'),
    ]));

    expect((string) $lines['shipping']->net->getAmount())->toBe('20.00')
        ->and((string) $lines['shipping']->tax->getAmount())->toBe('3.28')
        // No single rate to report, because there is no single rate.
        ->and($lines['shipping']->rate)->toBeNull()
        ->and($lines['shipping']->reason)->toContain('pro rata by net value')
        ->and($lines['shipping']->reason)->toContain('5.5%')
        ->and($lines['shipping']->reason)->toContain('20%');
});

it('splits equally when the document says to', function () {
    // Value tracks nothing about what a parcel costs to move. Two identical boxes,
    // one cheap and one dear, and an equal split is the defensible one — which is
    // why the basis is the caller's to state.
    $lines = assessOrder(($this->order)([
        new SupplyLine('food', Money::of('100.00', 'EUR'), TaxClass::Groceries),
        new SupplyLine('laptop', Money::of('300.00', 'EUR'), TaxClass::Electronics),
        deliveryLine('20.00'),
    ], ApportionmentBasis::Equal));

    // 10.00 at 5.5% + 10.00 at 20% = 0.55 + 2.00, against 3.28 by value. The
    // difference between the two bases is the reason neither can be assumed.
    expect((string) $lines['shipping']->tax->getAmount())->toBe('2.55');
});

it('never loses a minor unit to rounding', function () {
    // Three lines and a charge that does not divide: the shares must still sum to
    // the charge exactly. An invoice whose lines do not add up to its total is what
    // an auditor opens with.
    $lines = assessOrder(($this->order)([
        new SupplyLine('a', Money::of('10.00', 'EUR'), TaxClass::Groceries),
        new SupplyLine('b', Money::of('10.00', 'EUR'), TaxClass::Groceries),
        new SupplyLine('c', Money::of('10.00', 'EUR'), TaxClass::Groceries),
        deliveryLine('10.00'),
    ]));

    expect((string) $lines['shipping']->net->getAmount())->toBe('10.00')
        ->and((string) $lines['shipping']->gross->getAmount())->toBe('10.55');
});

it('carries the sign on a refunded delivery', function () {
    // Returning the order returns the postage, and it must refund the tax that was
    // charged on it rather than quietly refunding the net alone.
    $lines = assessOrder(($this->order)([
        new SupplyLine('food', Money::of('-100.00', 'EUR'), TaxClass::Groceries),
        deliveryLine('-10.00'),
    ]));

    expect((string) $lines['shipping']->tax->getAmount())->toBe('-0.55');
});

it('refuses an order that is nothing but delivery', function () {
    ($this->order)([deliveryLine('10.00')]);
})->throws(InvalidTaxOrder::class, 'nothing for it to be delivering');

it('leaves the caller line order intact', function () {
    // Delivery is assessed after the goods out of necessity. A host mapping
    // assessments onto invoice rows by position must not find them shuffled.
    $document = app(OrderTaxCalculator::class)->assessOrder(($this->order)([
        deliveryLine('10.00'),
        new SupplyLine('food', Money::of('100.00', 'EUR'), TaxClass::Groceries),
    ]));

    expect(array_map(static fn ($line): string => $line->id, $document->lines))
        ->toBe(['shipping', 'food']);
});
