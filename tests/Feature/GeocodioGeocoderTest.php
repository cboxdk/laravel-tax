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
    $http->fake([
        'api.geocod.io/*' => $http->response([
            'results' => [
                ['address_components' => ['country' => 'US', 'state' => 'CA']],
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
                    'address_components' => ['country' => 'US', 'state' => 'CA'],
                    'fields' => [
                        'census' => [
                            '2020' => ['county_fips' => '06001', 'county_name' => 'Alameda County'],
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
                ['address_components' => ['country' => 'US', 'state' => 'CA']],
            ],
        ]),
    ]);

    $geocoder = new GeocodioGeocoder($http, $this->geo, 'test-key');

    $jurisdiction = $geocoder->locate(['line1' => 'x', 'subdivision' => 'CA', 'country' => 'US']);

    expect($jurisdiction->locality)->toBeNull();
});
