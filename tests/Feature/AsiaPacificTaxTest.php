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
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

it('charges India IGST at 18% on a foreign B2C digital supply (OIDAR)', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('IN')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US')),
        category: TaxCategory::DigitalService,
    ));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('18.00')
        ->and($a->reason)->toContain('IGST');
});

it('reverse-charges an India B2B supply to a GST-registered recipient', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('IN')),
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode('US')),
        customerTaxIdValidated: true,
    ));

    expect($a->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and((string) $a->tax->getAmount())->toBe('0.00');
});

it('charges Singapore GST at 9%', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'SGD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('SG')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('SG')),
    ));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('9.00');
});
