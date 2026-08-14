<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\RateSource\StaticTaxRateSource;

// Prior windows are carried only where this package's own coverage docs record a
// dated, primary-source-verified change — Türkiye 10 Jul 2023, Saudi Arabia Jul
// 2020, Bahrain Jan 2022, Malaysia 1 Mar 2024.

function ratedAt(string $country, ?string $on): ?string
{
    $place = app(JurisdictionRepository::class)->find(new CountryCode($country))
        ?? throw new RuntimeException($country.' did not resolve.');

    $rate = new StaticTaxRateSource()->rateFor(
        $place,
        TaxClass::GeneralGoods,
        $on === null ? null : new DateTimeImmutable($on),
    );

    return $rate === null ? null : (string) $rate->percentage;
}

it('reprices a historical supply at the rate that applied then', function () {
    // Türkiye rose from 18% to 20% on 10 July 2023. Reissuing a 2023 invoice must
    // not silently apply today's rate — which is what happened before the rates
    // carried dates at all.
    expect(ratedAt('TR', '2023-01-01'))->toBe('18')
        ->and(ratedAt('TR', '2023-07-09'))->toBe('18')
        ->and(ratedAt('TR', '2023-07-10'))->toBe('20')
        ->and(ratedAt('TR', null))->toBe('20');
});

it('carries the other dated changes the coverage docs record', function () {
    expect(ratedAt('SA', '2020-06-30'))->toBe('5')     // ZATCA, 5% → 15% Jul 2020
        ->and(ratedAt('SA', '2020-07-01'))->toBe('15')
        ->and(ratedAt('BH', '2021-12-31'))->toBe('5')  // NBR, 5% → 10% Jan 2022
        ->and(ratedAt('BH', '2022-01-01'))->toBe('10')
        ->and(ratedAt('MY', '2024-02-29'))->toBe('6')  // RMCD service tax, 6% → 8%
        ->and(ratedAt('MY', '2024-03-01'))->toBe('8');
});

it('answers an open window for every date where no change is recorded', function () {
    // Absence of a prior window is not a claim that the rate never moved — it is
    // the honest statement that this package carries no dated change for it.
    foreach (['2015-01-01', '2026-08-06', '2030-01-01'] as $on) {
        expect(ratedAt('GB', $on))->toBe('20')
            ->and(ratedAt('DK', $on))->toBe('25');
    }
});

it('resolves a subdivision before its country, on the same date basis', function () {
    $place = app(JurisdictionRepository::class)->find(
        new CountryCode('CA'),
        new SubdivisionCode('CA-QC'),
    );

    expect((string) new StaticTaxRateSource()->rateFor($place, TaxClass::GeneralGoods)?->percentage)
        ->toBe('14.975');
});

it('still accepts a caller-supplied flat map, as one open window each', function () {
    $place = app(JurisdictionRepository::class)->find(new CountryCode('DK'));
    $source = new StaticTaxRateSource(['DK' => '12.5']);

    expect((string) $source->rateFor($place, TaxClass::GeneralGoods, new DateTimeImmutable('2001-01-01'))?->percentage)
        ->toBe('12.5');
});

it('exposes the current rates as a flat map for callers that want one', function () {
    $now = StaticTaxRateSource::defaults();

    expect($now['TR'])->toBe('20')
        ->and($now['SA'])->toBe('15')
        ->and($now)->toHaveKey('CA-QC');
});
