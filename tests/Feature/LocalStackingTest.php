<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\LocalityScheme;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\ValueObjects\TaxRate;

/*
 * Stacking: every authority that taxes an address, or none of them. A rate short by
 * one authority's share is an under-charge stamped authoritative, which is the one
 * outcome this package works hardest to prevent.
 */

function stackedAt(string $state, ?string $zip9): ?TaxRate
{
    $place = app(JurisdictionRepository::class)->find(new CountryCode('US'), new SubdivisionCode($state));

    if ($zip9 !== null) {
        $place = $place->withLocality(new LocalityCode(new SubdivisionCode($state), LocalityScheme::Zip9->value, $zip9));
    }

    return app(TaxRateSource::class)->rateFor($place, TaxClass::GeneralGoods);
}

it('sums the state, county and city that all levy at one address', function (): void {
    $rate = stackedAt('US-KS', '66101-1366');

    // 6.5 state + 1.0 county + 1.625 city
    expect((string) $rate?->percentage)->toBe('9.125')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative)
        ->and($rate?->hasComponents())->toBeTrue()
        ->and(array_map(fn ($c): string => $c->level->value, $rate?->components ?? []))
        ->toBe(['state', 'county', 'city']);
});

it('takes the narrow span over the whole-ZIP row that also covers the address', function (): void {
    $narrow = stackedAt('US-KS', '66002-5033');
    $whole = stackedAt('US-KS', '66002-0001');

    expect((string) $narrow?->percentage)->toBe('7.5')
        ->and((string) $whole?->percentage)->toBe('7.5');

    // Same total, different county — which is what a filing has to get right.
    expect(array_map(fn ($c): ?string => $c->code, $narrow?->components ?? []))
        ->toContain('us:KS:COUNTY-087')
        ->and(array_map(fn ($c): ?string => $c->code, $whole?->components ?? []))
        ->toContain('us:KS:COUNTY-005');
});

it('treats "no local authority here" as the whole rate, not a fallback', function (): void {
    $rate = stackedAt('US-KS', '67002-0001');

    expect((string) $rate?->percentage)->toBe('6.5')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative)
        ->and($rate?->limitedBy)->toBeNull();
});

it('says so when nothing resolved the address below the state line', function (): void {
    // A ZIP the boundary file does not carry. The state share is honest; calling it
    // authoritative would not be.
    $rate = stackedAt('US-KS', '99999-0000');

    expect((string) $rate?->percentage)->toBe('6.5')
        ->and($rate?->confidence)->toBe(Confidence::Derived)
        ->and($rate?->limitedBy)->toBe(RateLimit::NoLocalResolution);
});

it('does not stack at all without a locality', function (): void {
    $rate = stackedAt('US-KS', null);

    expect((string) $rate?->percentage)->toBe('6.5')
        ->and($rate?->hasComponents())->toBeFalse();
});
