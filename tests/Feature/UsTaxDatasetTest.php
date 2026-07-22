<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\NexusCombinator;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\SourcingMode;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\Nexus\UsTaxDatasetNexus;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\Sourcing\UsTaxDatasetSourcing;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\Taxability\UsTaxDatasetTaxability;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->dataset = new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/us-tax-dataset',
    );
});

function datasetPlace(string $state, ?LocalityCode $locality = null): Jurisdiction
{
    $j = test()->geo->find(new CountryCode('US'), new SubdivisionCode($state));

    return $locality === null ? $j : $j->withLocality($locality);
}

// ---- Rate source ---------------------------------------------------------

it('resolves an authoritative state rate at derived confidence for a US state', function () {
    $source = new UsTaxDatasetRateSource($this->dataset);

    $rate = $source->rateFor(datasetPlace('US-CA'), TaxCategory::Standard);

    expect((string) $rate->percentage)->toBe('7.25')
        ->and($rate->kind)->toBe(RateKind::Standard)
        ->and($rate->source)->toBe('us-tax-data')
        ->and($rate->confidence)->toBe(Confidence::Derived); // state-level, not rooftop all-in
});

it('returns null for a non-US jurisdiction so a chain falls through', function () {
    $source = new UsTaxDatasetRateSource($this->dataset);

    expect($source->rateFor($this->geo->find(new CountryCode('DE')), TaxCategory::Standard))->toBeNull();
});

it('applies a reduced-rate category rule over the general rate', function () {
    $source = new UsTaxDatasetRateSource($this->dataset);

    // Missouri taxes groceries at a reduced 1.225%.
    $rate = $source->rateFor(datasetPlace('US-MO'), TaxCategory::Grocery);

    expect((string) $rate->percentage)->toBe('1.225')
        ->and($rate->kind)->toBe(RateKind::Reduced)
        ->and($rate->confidence)->toBe(Confidence::Authoritative);
});

it('stacks a combined local record into an all-in rooftop rate', function () {
    $source = new UsTaxDatasetRateSource($this->dataset);

    // California is combined-basis: the local record is already all-in (10.75%).
    $locality = new LocalityCode(new SubdivisionCode('US-CA'), 'ca-place', '06:ALAMEDA');
    $rate = $source->rateFor(datasetPlace('US-CA', $locality), TaxCategory::Standard);

    expect((string) $rate->percentage)->toBe('10.75')
        ->and($rate->confidence)->toBe(Confidence::Authoritative); // rooftop
});

it('stacks a component local record onto the state share', function () {
    $source = new UsTaxDatasetRateSource($this->dataset);

    // North Carolina is component-basis: local 2% + state 4.75% = 6.75% all-in.
    $locality = new LocalityCode(new SubdivisionCode('US-NC'), 'county-fips', '001');
    $rate = $source->rateFor(datasetPlace('US-NC', $locality), TaxCategory::Standard);

    expect((string) $rate->percentage)->toBe('6.75')
        ->and($rate->confidence)->toBe(Confidence::Authoritative);
});

it('falls back to the state rate when a locality has no matching local record', function () {
    $source = new UsTaxDatasetRateSource($this->dataset);

    $locality = new LocalityCode(new SubdivisionCode('US-NC'), 'county-fips', 'ZZZ-unknown');
    $rate = $source->rateFor(datasetPlace('US-NC', $locality), TaxCategory::Standard);

    expect((string) $rate->percentage)->toBe('4.75') // NC state rate
        ->and($rate->confidence)->toBe(Confidence::Derived);
});

// ---- Taxability ----------------------------------------------------------

it('reads per-state, per-category taxability from the dataset', function () {
    $taxability = new UsTaxDatasetTaxability($this->dataset, new StaticProductTaxability);

    expect($taxability->isTaxable(datasetPlace('US-CA'), TaxCategory::Clothing))->toBeTrue()
        ->and($taxability->isTaxable(datasetPlace('US-CA'), TaxCategory::DigitalService))->toBeFalse()
        ->and($taxability->isTaxable(datasetPlace('US-CA'), TaxCategory::Grocery))->toBeFalse()
        ->and($taxability->isTaxable(datasetPlace('US-NY'), TaxCategory::DigitalService))->toBeTrue();
});

it('delegates non-US taxability to the fallback matrix', function () {
    $taxability = new UsTaxDatasetTaxability($this->dataset, new StaticProductTaxability);

    // Standard goods are taxable everywhere by the fallback default.
    expect($taxability->isTaxable($this->geo->find(new CountryCode('DE')), TaxCategory::Standard))->toBeTrue();
});

// ---- Nexus ---------------------------------------------------------------

it('reads economic-nexus thresholds from the dataset', function () {
    $nexus = new UsTaxDatasetNexus($this->dataset);

    $tx = $nexus->for(new SubdivisionCode('US-TX'));

    expect($tx)->not->toBeNull()
        ->and($tx->salesDollars)->toBe(500_000)
        ->and($tx->transactions)->toBeNull()
        ->and($tx->combinator)->toBe(NexusCombinator::SalesOnly)
        ->and($nexus->for(new SubdivisionCode('US-DE')))->toBeNull(); // no sales tax
});

// ---- Sourcing ------------------------------------------------------------

it('reads intrastate sourcing rules from the dataset', function () {
    $sourcing = new UsTaxDatasetSourcing($this->dataset);

    $ca = $sourcing->for(new SubdivisionCode('US-CA'));
    $tx = $sourcing->for(new SubdivisionCode('US-TX'));

    expect($ca->mode)->toBe(SourcingMode::Mixed)
        ->and($ca->note)->not->toBeNull()
        ->and($tx->mode)->toBe(SourcingMode::Origin)
        ->and($tx->note)->toBeNull();
});

it('binds SourcingRules to the dataset by default', function () {
    expect($this->app->make(SourcingRules::class))->toBeInstanceOf(UsTaxDatasetSourcing::class)
        ->and($this->app->make(SourcingRules::class)->for(new SubdivisionCode('US-TX'))->mode)->toBe(SourcingMode::Origin);
});

// ---- Deny-by-default -----------------------------------------------------

it('denies (returns null) when the dataset location is unreadable', function () {
    $missing = new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        '/no/such/dataset/dir',
    );

    $source = new UsTaxDatasetRateSource($missing);

    expect($source->rateFor(datasetPlace('US-CA'), TaxCategory::Standard))->toBeNull();
});
