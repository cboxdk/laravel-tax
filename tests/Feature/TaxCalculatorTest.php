<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\UnresolvedTaxRate;
use Cbox\Tax\Exceptions\UnsupportedJurisdiction;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

function place(string $country)
{
    return test()->geo->find(new CountryCode($country));
}

function seller(string $country): SellerRegistrations
{
    return new SellerRegistrations(new CountryCode($country));
}

it('charges domestic VAT on a tax-exclusive B2C supply', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: place('DK'),
        customer: CustomerType::Consumer,
        seller: seller('DK'),
    ));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('25.00')
        ->and((string) $a->gross->getAmount())->toBe('125.00');
});

it('extracts VAT from a tax-inclusive amount', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('125.00', 'EUR'),
        pricing: Pricing::Inclusive,
        place: place('DK'),
        customer: CustomerType::Consumer,
        seller: seller('DK'),
    ));

    expect((string) $a->net->getAmount())->toBe('100.00')
        ->and((string) $a->tax->getAmount())->toBe('25.00');
});

it('reverse-charges an intra-EU B2B supply to a validated customer', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: place('FR'),
        customer: CustomerType::Business,
        seller: seller('DE'),
        customerTaxIdValidated: true,
    ));

    expect($a->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and((string) $a->tax->getAmount())->toBe('0.00')
        ->and($a->rate)->toBeNull();
});

it('charges destination VAT to an unvalidated cross-border business', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: place('FR'),
        customer: CustomerType::Business,
        seller: seller('DE'),
        customerTaxIdValidated: false,
    ));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('20.00'); // FR 20%
});

it('charges destination VAT on a cross-border B2C digital supply', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: place('FR'),
        customer: CustomerType::Consumer,
        seller: seller('DE'),
        category: TaxCategory::DigitalService,
    ));

    expect((string) $a->tax->getAmount())->toBe('20.00');
});

it('routes tax by the selling entity: same buyer, different seller, different tax', function () {
    $buyer = fn (string $sellerCountry) => new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: place('FR'),
        customer: CustomerType::Business,
        seller: seller($sellerCountry),
        customerTaxIdValidated: true,
    );

    // German entity → cross-border → reverse charge, no French VAT.
    $viaDe = $this->tax->assess($buyer('DE'));
    // French entity → domestic supply → French VAT is charged.
    $viaFr = $this->tax->assess($buyer('FR'));

    expect($viaDe->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and((string) $viaDe->tax->getAmount())->toBe('0.00')
        ->and($viaFr->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $viaFr->tax->getAmount())->toBe('20.00');
});

it('handles a national (non-EU) regime — UK domestic VAT', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'GBP'),
        pricing: Pricing::Exclusive,
        place: place('GB'),
        customer: CustomerType::Consumer,
        seller: seller('GB'),
    ));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('20.00');
});

it('refuses an unmodelled (sub-federal) jurisdiction rather than guessing', function () {
    $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: place('US'),
        customer: CustomerType::Consumer,
        seller: seller('US'),
    ));
})->throws(UnsupportedJurisdiction::class);

it('refuses to assess when no rate is available rather than assuming 0%', function () {
    $calc = $this->taxCalculator(['DE' => '19']); // no FR rate

    $calc->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: place('FR'),
        customer: CustomerType::Consumer,
        seller: seller('FR'),
    ));
})->throws(UnresolvedTaxRate::class);

it('rounds tax half-up on an odd net amount', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('99.99', 'EUR'),
        pricing: Pricing::Exclusive,
        place: place('DK'),
        customer: CustomerType::Consumer,
        seller: seller('DK'),
    ));

    // 99.99 * 25% = 24.9975 -> 25.00 half-up
    expect((string) $a->tax->getAmount())->toBe('25.00')
        ->and((string) $a->gross->getAmount())->toBe('124.99');
});
