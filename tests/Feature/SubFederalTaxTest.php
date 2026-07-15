<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\JurisdictionNotResolved;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

function usSeller(string $state): SellerRegistrations
{
    return new SellerRegistrations(new CountryCode('US'), [
        new SellerRegistration(new CountryCode('US'), new SubdivisionCode($state)),
    ]);
}

function usBuyer(string $sellerState, string $buyerState, TaxCategory $category = TaxCategory::Standard): TaxQuery
{
    return new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('US'), new SubdivisionCode($buyerState)),
        customer: CustomerType::Consumer,
        seller: usSeller($sellerState),
        category: $category,
    );
}

it('charges US sales tax when the seller has nexus in the state', function () {
    $a = $this->tax->assess(usBuyer('US-CA', 'US-CA'));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('7.25') // CA 7.25%
        ->and((string) $a->gross->getAmount())->toBe('107.25');
});

it('does not collect where the seller has no nexus', function () {
    $a = $this->tax->assess(usBuyer('US-NY', 'US-CA')); // registered in NY, selling to CA

    expect($a->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and((string) $a->tax->getAmount())->toBe('0.00');
});

it('exempts a product that is not taxable in the state', function () {
    $registry = DefaultRegimeRegistry::withDefaults(
        new StaticProductTaxability(['US-CA:digital_service' => false]),
    );
    $calc = new DefaultTaxCalculator($registry, new StaticTaxRateSource);

    $a = $calc->assess(usBuyer('US-CA', 'US-CA', TaxCategory::DigitalService));

    expect($a->treatment)->toBe(TaxTreatment::Exempt)
        ->and((string) $a->tax->getAmount())->toBe('0.00');
});

it('refuses US sales tax without a resolved state', function () {
    $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('US')), // no subdivision
        customer: CustomerType::Consumer,
        seller: usSeller('US-CA'),
    ));
})->throws(JurisdictionNotResolved::class);

it('charges the Canadian province combined rate', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'CAD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('CA'), new SubdivisionCode('CA-ON')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('CA')),
    ));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('13.00'); // ON HST 13%
});

it('reverse-charges a cross-border B2B supply into Canada', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'CAD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('CA'), new SubdivisionCode('CA-ON')),
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode('US')),
        customerTaxIdValidated: true,
    ));

    expect($a->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and((string) $a->tax->getAmount())->toBe('0.00');
});
