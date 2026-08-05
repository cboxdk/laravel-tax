<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\RateSource\ArcGisRateSource;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

// Payloads are the real shape returned by CDTFA's and TRD's feature services,
// captured 2026-08-05: California publishes the rate as a FRACTION (0.0975) and
// New Mexico as a PERCENTAGE (7.625), which is the difference this must not get
// wrong in either direction.

function gisPlace(string $state, ?string $point = null, string $scheme = ArcGisRateSource::LATLNG_SCHEME): Jurisdiction
{
    $subdivision = new SubdivisionCode($state);
    $j = app(JurisdictionRepository::class)->find(new CountryCode('US'), $subdivision)
        ?? throw new RuntimeException($state.' did not resolve.');

    return $point === null ? $j : $j->withLocality(new LocalityCode($subdivision, $scheme, $point));
}

function gisSource(Factory $http, ?Cache $cache = null): ArcGisRateSource
{
    return new ArcGisRateSource($http, $cache);
}

function gisResponse(Factory $http, array $attributes)
{
    return $http->response(['features' => [['attributes' => $attributes]]]);
}

it('reads California a fraction and returns a percentage', function () {
    $http = new Factory;
    $http->fake(['*' => gisResponse($http, ['RATE' => 0.0975, 'JURIS_NAME' => 'LOS ANGELES'])]);

    $rate = gisSource($http)->rateFor(gisPlace('US-CA', '34.052200,-118.243700'), TaxCategory::Standard);

    expect((string) $rate?->percentage)->toBe('9.75')
        ->and($rate?->source)->toBe('state-gis')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative);
});

it('reads New Mexico a percentage and leaves it alone', function () {
    $http = new Factory;
    $http->fake(['*' => gisResponse($http, ['grt_rate' => 7.625, 'locat_cdr' => '02-100'])]);

    expect((string) gisSource($http)->rateFor(gisPlace('US-NM', '35.084400,-106.650400'), TaxCategory::Standard)?->percentage)
        ->toBe('7.625');
});

it('queries the point as an intersecting geometry', function () {
    $http = new Factory;
    $http->fake(['*' => gisResponse($http, ['RATE' => 0.0975, 'JURIS_NAME' => 'LOS ANGELES'])]);

    gisSource($http)->rateFor(gisPlace('US-CA', '34.052200,-118.243700'), TaxCategory::Standard);

    $http->assertSent(function ($request): bool {
        $url = urldecode($request->url());

        return str_contains($url, 'esriSpatialRelIntersects')
            && str_contains($url, '"x":-118.2437')          // lng is x, lat is y
            && str_contains($url, '"y":34.0522');
    });
});

it('answers only for the states that publish polygons', function () {
    $http = new Factory;
    $http->fake(['*' => gisResponse($http, ['RATE' => 0.0825, 'JURIS_NAME' => 'AUSTIN'])]);

    expect(gisSource($http)->rateFor(gisPlace('US-TX', '30.267200,-97.743100'), TaxCategory::Standard))->toBeNull();

    $http->assertNothingSent();
});

it('ignores a locality that is not a point', function () {
    $http = new Factory;
    $http->fake(['*' => gisResponse($http, ['RATE' => 0.0975, 'JURIS_NAME' => 'LOS ANGELES'])]);

    // A ZIP+4 locality belongs to the dataset source, not this one.
    expect(gisSource($http)->rateFor(gisPlace('US-CA', '90012-4801', 'zip9'), TaxCategory::Standard))->toBeNull();

    $http->assertNothingSent();
});

it('refuses coordinates that are not plausible rather than querying', function () {
    $http = new Factory;
    $http->fake(['*' => gisResponse($http, ['RATE' => 0.0975, 'JURIS_NAME' => 'X'])]);
    $source = gisSource($http);

    foreach (['91.0,-118.2', '34.0,-181.0', 'north,west', '34.0'] as $bad) {
        expect($source->rateFor(gisPlace('US-CA', $bad), TaxCategory::Standard))->toBeNull();
    }

    $http->assertNothingSent();
});

it('denies by default when the point falls in no polygon', function () {
    $http = new Factory;
    $http->fake(['*' => $http->response(['features' => []])]);

    // Off the California coast: a real answer of "nowhere", not an error.
    expect(gisSource($http)->rateFor(gisPlace('US-CA', '34.000000,-125.000000'), TaxCategory::Standard))->toBeNull();
});

it('denies by default when the service fails', function () {
    $http = new Factory;
    $http->fake(['*' => $http->response('gateway timeout', 504)]);

    expect(gisSource($http)->rateFor(gisPlace('US-CA', '34.052200,-118.243700'), TaxCategory::Standard))->toBeNull();
});

it('caches on the point, including a miss', function () {
    $http = new Factory;
    $http->fake(['*' => $http->response(['features' => []])]);
    $source = gisSource($http, app('cache')->store());

    $source->rateFor(gisPlace('US-CA', '34.000000,-125.000000'), TaxCategory::Standard);
    $source->rateFor(gisPlace('US-CA', '34.000000,-125.000000'), TaxCategory::Standard);

    // A point in the sea must not re-query on every assessment.
    $http->assertSentCount(1);
});
