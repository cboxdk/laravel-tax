<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\ValueObjects\OssStatus;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

beforeEach(function (): void {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

/** A German seller shipping a B2C supply to a French consumer, with a given OSS status. */
function deToFrB2c(?OssStatus $oss): TaxQuery
{
    return new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('FR')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DE'), oss: $oss),
    );
}

it('sources at origin (German VAT) for a below-threshold, non-opted micro-business', function (): void {
    $a = $this->tax->assess(deToFrB2c(new OssStatus(registered: false, thresholdExceeded: false)));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and($a->placeOfSupply->country->value)->toBe('DE')
        ->and((string) $a->tax->getAmount())->toBe('19.00') // DE 19%, not FR 20%
        ->and($a->reason)->toContain('origin');
});

it('sources at destination (French VAT) once the €10k threshold is exceeded', function (): void {
    $a = $this->tax->assess(deToFrB2c(new OssStatus(registered: false, thresholdExceeded: true)));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and($a->placeOfSupply->country->value)->toBe('FR')
        ->and((string) $a->tax->getAmount())->toBe('20.00'); // FR 20%
});

it('sources at destination (French VAT) when the seller has opted into OSS', function (): void {
    $a = $this->tax->assess(deToFrB2c(new OssStatus(registered: true, thresholdExceeded: false)));

    expect($a->placeOfSupply->country->value)->toBe('FR')
        ->and((string) $a->tax->getAmount())->toBe('20.00');
});

it('defaults to destination when the seller asserts no OSS status', function (): void {
    $a = $this->tax->assess(deToFrB2c(null));

    expect($a->placeOfSupply->country->value)->toBe('FR')
        ->and((string) $a->tax->getAmount())->toBe('20.00');
});

it('leaves B2B reverse-charge unchanged regardless of OSS status', function (): void {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('FR')),
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode('DE'), oss: new OssStatus(registered: false, thresholdExceeded: false)),
        customerTaxIdValidated: true,
    ));

    expect($a->treatment)->toBe(TaxTreatment::IntraCommunitySupply)
        ->and($a->isReverseCharge())->toBeTrue()
        ->and((string) $a->tax->getAmount())->toBe('0.00');
});

it('does not grant origin relief to a non-EU seller shipping into the EU', function (): void {
    // A US micro-business selling B2C into France must charge destination VAT — the
    // €10k relief is for EU-established sellers only. Registered in France so it is
    // its tax to collect; below the threshold, so relief WOULD apply to an EU seller.
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('FR')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [new SellerRegistration(new CountryCode('FR'))], new OssStatus(registered: false, thresholdExceeded: false)),
    ));

    expect($a->placeOfSupply->country->value)->toBe('FR')
        ->and((string) $a->tax->getAmount())->toBe('20.00');
});

it('keeps a domestic German B2C supply on German VAT', function (): void {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('DE')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DE'), oss: new OssStatus(registered: false, thresholdExceeded: false)),
    ));

    expect($a->placeOfSupply->country->value)->toBe('DE')
        ->and((string) $a->tax->getAmount())->toBe('19.00');
});
