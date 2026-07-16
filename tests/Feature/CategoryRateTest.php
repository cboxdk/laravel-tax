<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\ValueObjects\RateBand;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
});

it('ships no reduced bands by default — every category resolves the standard rate', function () {
    $source = new StaticTaxRateSource;
    $fr = $this->geo->find(new CountryCode('FR'));

    $standard = $source->rateFor($fr, TaxCategory::Standard);
    $digital = $source->rateFor($fr, TaxCategory::DigitalService);

    expect($standard->kind)->toBe(RateKind::Standard)
        ->and($digital->kind)->toBe(RateKind::Standard)
        ->and((string) $digital->percentage)->toBe('20');
});

it('resolves a configured reduced band for a category, else the standard rate', function () {
    // Test-source band, not shipped national data: a reduced digital-service rate in FR.
    $source = new StaticTaxRateSource(null, [
        'FR:digital_service' => new RateBand('5.5', RateKind::Reduced),
    ]);
    $fr = $this->geo->find(new CountryCode('FR'));

    $digital = $source->rateFor($fr, TaxCategory::DigitalService);
    $standard = $source->rateFor($fr, TaxCategory::Standard);

    expect((string) $digital->percentage)->toBe('5.5')
        ->and($digital->kind)->toBe(RateKind::Reduced)
        ->and((string) $standard->percentage)->toBe('20') // unaffected
        ->and($standard->kind)->toBe(RateKind::Standard);
});

it('resolves a zero band and leaves other jurisdictions on standard', function () {
    $source = new StaticTaxRateSource(null, [
        'DK:digital_service' => new RateBand('0', RateKind::Zero),
    ]);

    $dk = $source->rateFor($this->geo->find(new CountryCode('DK')), TaxCategory::DigitalService);
    $de = $source->rateFor($this->geo->find(new CountryCode('DE')), TaxCategory::DigitalService);

    expect($dk->isZero())->toBeTrue()
        ->and($dk->kind)->toBe(RateKind::Zero)
        ->and((string) $de->percentage)->toBe('19'); // no band -> standard
});

it('drives a reduced band end-to-end through the calculator', function () {
    $calc = $this->taxCalculator(null, ['FR:digital_service' => new RateBand('5.5', RateKind::Reduced)]);

    $a = $calc->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('FR')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('FR')), // domestic FR supply
        category: TaxCategory::DigitalService,
    ));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and($a->rate->kind)->toBe(RateKind::Reduced)
        ->and((string) $a->tax->getAmount())->toBe('5.50')
        ->and((string) $a->gross->getAmount())->toBe('105.50');
});
