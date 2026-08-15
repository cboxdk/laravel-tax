<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\EuTaxData\EuTaxDataset;
use Cbox\Tax\RateSource\EuTaxDatasetRateSource;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\RateProvenance;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
});

/** The EU source reading a LOCAL fixture — no manifest, so nothing to trace to. */
function euLocalSource(): EuTaxDatasetRateSource
{
    return new EuTaxDatasetRateSource(new EuTaxDataset(
        app(Factory::class),
        app(Cache::class),
        dirname(__DIR__).'/Fixtures/eu-tax-dataset',
    ));
}

/** The same fixture served over a faked HTTP client, so the manifest is read. */
function euRemoteSource(): EuTaxDatasetRateSource
{
    $dir = dirname(__DIR__).'/Fixtures/eu-tax-dataset/';

    Http::fake([
        '*/manifest.json' => Http::response((string) file_get_contents($dir.'manifest.json')),
        '*/by-section/rates.json' => Http::response((string) file_get_contents($dir.'by-section/rates.json')),
        '*/by-section/class-map.json' => Http::response((string) file_get_contents($dir.'by-section/class-map.json')),
    ]);

    return new EuTaxDatasetRateSource(new EuTaxDataset(
        app(Factory::class),
        app(Cache::class),
        'https://example.test/eu',
    ));
}

// ---------------------------------------------------------------------------
// What is recorded, and why the window matters more than the version
// ---------------------------------------------------------------------------

it('records the window the answer stood on, not just which dataset answered', function () {
    $rate = euRemoteSource()->rateFor($this->geo->find(new CountryCode('HU')), TaxClass::GeneralGoods);

    // The version alone would put every invoice in the blast radius of every
    // republish. The window's start is what a correction actually names.
    expect($rate?->provenance)->toBeInstanceOf(RateProvenance::class)
        ->and($rate?->provenance?->dataset)->toBe('eu-tax-dataset')
        ->and($rate?->provenance?->effectiveFrom)->toBe('2024-01-01')
        ->and($rate?->provenance?->version)->not->toBeNull()
        ->and($rate?->provenance?->isTraceable())->toBeTrue();
});

it('records the section hash, which is finer than the artifact hash', function () {
    $rate = euRemoteSource()->rateFor($this->geo->find(new CountryCode('HU')), TaxClass::GeneralGoods);

    // A taxability correction moves the whole artifact's content hash but not the
    // rates section's, so an assessment that only read rates can be ruled out of a
    // reconciliation without being re-run.
    expect($rate?->provenance?->sectionHash)->toBeString()
        ->and(strlen((string) $rate?->provenance?->sectionHash))->toBe(64);
});

it('stamps every outcome, including the ones that fell back', function () {
    $source = euRemoteSource();
    $place = $this->geo->find(new CountryCode('HU'));

    // An undecided heading, a resolved commodity code, and a settled band all have
    // to be traceable. A reconciliation that could only see the exact answers would
    // miss precisely the lines most likely to have been wrong.
    expect($source->rateFor($place, TaxClass::Groceries)?->provenance)->not->toBeNull()
        ->and($source->rateForCommodity($place, TaxClass::Groceries, 'cn:01022110')?->provenance)->not->toBeNull()
        ->and($source->rateFor($place, TaxClass::Accommodation)?->provenance)->not->toBeNull();
});

it('records the US state window too', function () {
    $rate = new UsTaxDatasetRateSource(new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/us-tax-dataset',
    ))->rateFor(
        $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-TX')),
        TaxClass::GeneralGoods,
    );

    expect($rate?->provenance?->dataset)->toBe('us-tax-data');
});

// ---------------------------------------------------------------------------
// What it honestly cannot promise
// ---------------------------------------------------------------------------

it('still records the version from a local MIRROR of the published data', function () {
    // A local path skips VERIFICATION — a deliberate trust decision, because
    // pointing this at your own disk is something you did on purpose. It does not
    // skip recording what was read: the mirror carries the publisher's manifest, so
    // the version is real and the assessment stays traceable.
    $rate = euLocalSource()->rateFor($this->geo->find(new CountryCode('HU')), TaxClass::GeneralGoods);

    expect($rate?->provenance?->isTraceable())->toBeTrue()
        ->and($rate?->provenance?->version)->toBeString();
});

it('records nothing for a source that publishes nothing to trace back to', function () {
    $rate = new StaticTaxRateSource()->rateFor($this->geo->find(new CountryCode('DK')), TaxClass::GeneralGoods);

    expect($rate?->provenance)->toBeNull();
});

it('serializes flat, for a column on an invoice line', function () {
    $provenance = new RateProvenance('us-tax-data', '2026-08-15', '2022-01-01', str_repeat('a', 64));

    expect($provenance->toArray())->toBe([
        'dataset' => 'us-tax-data',
        'version' => '2026-08-15',
        'effectiveFrom' => '2022-01-01',
        'sectionHash' => str_repeat('a', 64),
    ]);
});
