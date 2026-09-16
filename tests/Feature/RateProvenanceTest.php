<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\ValueObjects\RateProvenance;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
});

/**
 * The register's EU rates.
 *
 * There is no local-versus-remote split any more: the engine reads a COMPILED store
 * whose manifest is written at sync time, so every answer is traceable to a version
 * whether or not the machine has a network. That distinction used to matter because
 * a local dataset directory had no manifest to name.
 */
function euLocalSource(): RegisterRateSource
{
    return new RegisterRateSource(app(RegisterDataset::class));
}

function euRemoteSource(): RegisterRateSource
{
    return euLocalSource();
}

// ---------------------------------------------------------------------------
// What is recorded, and why the window matters more than the version
// ---------------------------------------------------------------------------

it('records the window the answer stood on, not just which dataset answered', function () {
    $rate = euRemoteSource()->rateFor($this->geo->find(new CountryCode('HU')), TaxClass::GeneralGoods);

    // The version alone would put every invoice in the blast radius of every
    // republish. The window's start is what a correction actually names.
    expect($rate?->provenance)->toBeInstanceOf(RateProvenance::class)
        ->and($rate?->provenance?->dataset)->toBe('cbox-tax')
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
    $rate = app(TaxRateSource::class)->rateFor(
        $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-TX')),
        TaxClass::GeneralGoods,
    );

    expect($rate?->provenance?->dataset)->toBe('cbox-tax');
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

it('records provenance on every answer, because there is always a version to name', function () {
    // This used to test the opposite: a local dataset directory had no manifest, so
    // a rate read from one was untraceable. The engine reads a COMPILED store now
    // and the compiler writes a manifest, so there is no such thing as an answer
    // that cannot be traced to a release.
    $rate = rateSourceFor([])->rateFor($this->geo->find(new CountryCode('DK')), TaxClass::GeneralGoods);

    expect($rate?->provenance)->not->toBeNull()
        ->and($rate?->provenance?->version)->not->toBeNull()
        ->and($rate?->provenance?->isTraceable())->toBeTrue();
});

it('serializes flat, for a column on an invoice line', function () {
    $provenance = new RateProvenance('cbox-tax', '2026-08-15', '2022-01-01', str_repeat('a', 64));

    expect($provenance->toArray())->toBe([
        'dataset' => 'cbox-tax',
        'version' => '2026-08-15',
        'effectiveFrom' => '2022-01-01',
        'sectionHash' => str_repeat('a', 64),
    ]);
});
