<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Tax\Geocoder\GeocodioGeocoder;
use Illuminate\Http\Client\Factory;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
});

it('resolves an address to a jurisdiction with its subdivision', function () {
    $http = new Factory;
    // v2 shape: no top-level `input`, and `state` is now `state_province`.
    $http->fake([
        'api.geocod.io/*' => $http->response([
            'results' => [
                ['address_components' => ['country' => 'US', 'state_province' => 'CA', 'postal_code' => '94043']],
            ],
        ]),
    ]);

    $geocoder = new GeocodioGeocoder($http, $this->geo, 'test-key');

    $jurisdiction = $geocoder->locate([
        'line1' => '1600 Amphitheatre Pkwy',
        'city' => 'Mountain View',
        'subdivision' => 'CA',
        'postalCode' => '94043',
        'country' => 'US',
    ]);

    expect($jurisdiction)->not->toBeNull()
        ->and($jurisdiction->country->value)->toBe('US')
        ->and($jurisdiction->subdivision->value)->toBe('US-CA')
        ->and($jurisdiction->taxProfile->requiresRooftop)->toBeTrue();
});

it('targets the v2 endpoint by default', function () {
    $http = new Factory;
    $http->fake([
        'api.geocod.io/*' => $http->response([
            'results' => [
                ['address_components' => ['country' => 'US', 'state_province' => 'CA']],
            ],
        ]),
    ]);

    new GeocodioGeocoder($http, $this->geo, 'test-key')->locate([
        'line1' => '1600 Amphitheatre Pkwy',
        'country' => 'US',
    ]);

    $http->assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.geocod.io/v2/geocode'));
});

it('still reads the v1 `state` key so a pinned older baseUrl keeps resolving', function () {
    $http = new Factory;
    $http->fake([
        'api.geocod.io/*' => $http->response([
            'input' => ['address_components' => ['country' => 'US', 'state' => 'CA']],
            'results' => [
                ['address_components' => ['country' => 'US', 'state' => 'CA', 'zip' => '94043']],
            ],
        ]),
    ]);

    $geocoder = new GeocodioGeocoder($http, $this->geo, 'test-key', 'https://api.geocod.io/v1.7');

    expect($geocoder->locate(['line1' => '1600 Amphitheatre Pkwy', 'country' => 'US'])?->subdivision->value)
        ->toBe('US-CA');
});

it('denies by default on an empty geocoding result', function () {
    $http = new Factory;
    $http->fake([
        'api.geocod.io/*' => $http->response(['results' => []]),
    ]);

    $geocoder = new GeocodioGeocoder($http, $this->geo, 'test-key');

    expect($geocoder->locate(['line1' => 'nowhere', 'country' => 'US']))->toBeNull();
});

it('attaches a county-FIPS locality from the census fields when rooftop is enabled', function () {
    $http = new Factory;
    $http->fake([
        'api.geocod.io/*' => $http->response([
            'results' => [
                [
                    'address_components' => ['country' => 'US', 'state_province' => 'CA'],
                    'fields' => [
                        'census' => [
                            // v2 returns the census append unchanged from v1: a
                            // state-prefixed county FIPS, plus a `place` object.
                            '2025' => [
                                'county_fips' => '06001',
                                'county_name' => 'Alameda County',
                                'place' => ['name' => 'Oakland', 'fips' => '0653000'],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $geocoder = new GeocodioGeocoder($http, $this->geo, 'test-key', rooftop: true);

    $jurisdiction = $geocoder->locate([
        'line1' => '1 Frank H Ogawa Plaza',
        'city' => 'Oakland',
        'subdivision' => 'CA',
        'country' => 'US',
    ]);

    expect($jurisdiction)->not->toBeNull()
        ->and($jurisdiction->subdivision->value)->toBe('US-CA')
        ->and($jurisdiction->locality)->not->toBeNull()
        ->and($jurisdiction->locality->scheme)->toBe('county-fips')
        ->and($jurisdiction->locality->value)->toBe('06001')
        ->and($jurisdiction->needsRooftop())->toBeFalse();
});

it('does not attach a locality when rooftop is disabled (the default)', function () {
    $http = new Factory;
    $http->fake([
        'api.geocod.io/*' => $http->response([
            'results' => [
                ['address_components' => ['country' => 'US', 'state_province' => 'CA']],
            ],
        ]),
    ]);

    $geocoder = new GeocodioGeocoder($http, $this->geo, 'test-key');

    $jurisdiction = $geocoder->locate(['line1' => 'x', 'subdivision' => 'CA', 'country' => 'US']);

    expect($jurisdiction->locality)->toBeNull();
});
