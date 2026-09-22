<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Tax\Contracts\AddressGeocoder;
use Cbox\Tax\Enums\LocalityScheme;
use Cbox\Tax\Geocoder\GeocodioGeocoder;
use Cbox\Tax\TaxServiceProvider;
use Illuminate\Http\Client\Factory;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
});

it('uses the geocodio rooftop configuration when binding the adapter', function (bool $enabled): void {
    config()->set('tax.geocodio.key', 'test-key');
    config()->set('tax.geocodio.rooftop', $enabled);
    new TaxServiceProvider(app())->register();

    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake(['api.geocod.io/*' => $http->response(['results' => [[
        'address_components' => ['country' => 'US', 'state_province' => 'KS'],
        'fields' => ['zip4' => ['zip9' => ['66101-3064']]],
    ]]])]);

    $place = app(AddressGeocoder::class)->locate(['line1' => '701 N 7th St', 'country' => 'US']);

    expect($place?->locality?->value)->toBe($enabled ? '66101-3064' : null);
    $http->assertSent(fn ($request): bool => isset($request['fields']) === $enabled);
})->with([true, false]);

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

it('attaches a ZIP+4 locality from the zip4 append when rooftop is enabled', function () {
    // Washington is a Streamlined member, so its rooftop key is the postal one the
    // boundary index carries — unlike California, which is resolved by polygon.
    $http = new Factory;
    $http->fake([
        'api.geocod.io/*' => $http->response([
            'results' => [
                [
                    'address_components' => ['country' => 'US', 'state_province' => 'WA'],
                    'fields' => [
                        'zip4' => ['plus4' => ['4607'], 'zip9' => ['98109-4607']],
                    ],
                ],
            ],
        ]),
    ]);

    $geocoder = new GeocodioGeocoder($http, $this->geo, 'test-key', rooftop: true);

    $jurisdiction = $geocoder->locate([
        'line1' => '400 Broad St',
        'city' => 'Seattle',
        'subdivision' => 'WA',
        'country' => 'US',
    ]);

    expect($jurisdiction)->not->toBeNull()
        ->and($jurisdiction->subdivision->value)->toBe('US-WA')
        ->and($jurisdiction->locality)->not->toBeNull()
        ->and($jurisdiction->locality->scheme)->toBe(LocalityScheme::Zip9->value)
        ->and($jurisdiction->locality->value)->toBe('98109-4607')
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

it('refuses an ambiguous ZIP+4 rather than picking one', function () {
    // Geocodio returns zip9 as a list; an address spanning several add-ons could
    // straddle a jurisdiction line, so no locality is attached and the state rate
    // applies.
    $http = new Factory;
    $http->fake([
        'api.geocod.io/*' => $http->response([
            'results' => [
                [
                    'address_components' => ['country' => 'US', 'state_province' => 'WA'],
                    'fields' => ['zip4' => ['zip9' => ['98109-4607', '98109-4608']]],
                ],
            ],
        ]),
    ]);

    $jurisdiction = new GeocodioGeocoder($http, $this->geo, 'test-key', rooftop: true)
        ->locate(['line1' => '1 Frank H Ogawa Plaza', 'country' => 'US']);

    expect($jurisdiction?->locality)->toBeNull();
});

it('requests the zip4 append, not census, when rooftop is enabled', function () {
    $http = new Factory;
    $http->fake(['api.geocod.io/*' => $http->response(['results' => [
        ['address_components' => ['country' => 'US', 'state_province' => 'CA']],
    ]])]);

    new GeocodioGeocoder($http, $this->geo, 'test-key', rooftop: true)
        ->locate(['line1' => 'x', 'country' => 'US']);

    $http->assertSent(fn ($request): bool => str_contains($request->url(), 'fields=zip4'));
});

it('retries a transient failure before giving up', function () {
    // Geocodio answers 403 "Invalid API key" intermittently on a valid key. With
    // rooftop that is not a degraded rate, it is a failed assessment — so one
    // failure is not believed on its own.
    $http = new Factory;
    $http->fake([
        'api.geocod.io/*' => $http->sequence()
            ->push(['error' => 'Invalid API key'], 403)
            ->push(['results' => [
                ['address_components' => ['country' => 'US', 'state_province' => 'CA']],
            ]], 200),
    ]);

    $jurisdiction = new GeocodioGeocoder($http, $this->geo, 'test-key')
        ->locate(['line1' => '1600 Amphitheatre Pkwy', 'country' => 'US']);

    expect($jurisdiction?->subdivision->value)->toBe('US-CA');
    $http->assertSentCount(2);
});

it('still denies when the failure persists', function () {
    $http = new Factory;
    $http->fake(['api.geocod.io/*' => $http->response(['error' => 'Invalid API key'], 403)]);

    expect(new GeocodioGeocoder($http, $this->geo, 'test-key')->locate(['line1' => 'x', 'country' => 'US']))
        ->toBeNull();
});

it('attaches a point locality for the states resolved by polygon', function () {
    // California and New Mexico publish polygon services, so a point is the useful
    // key there — a jurisdiction carries only one locality, so the ZIP+4 is not.
    $http = new Factory;
    $http->fake([
        'api.geocod.io/*' => $http->response([
            'results' => [
                [
                    'address_components' => ['country' => 'US', 'state_province' => 'CA'],
                    'location' => ['lat' => 34.0522, 'lng' => -118.2437],
                    'fields' => ['zip4' => ['zip9' => ['90012-4801']]],
                ],
            ],
        ]),
    ]);

    $jurisdiction = new GeocodioGeocoder($http, $this->geo, 'test-key', rooftop: true)
        ->locate(['line1' => '200 N Spring St', 'city' => 'Los Angeles', 'country' => 'US']);

    expect($jurisdiction?->locality?->scheme)->toBe(LocalityScheme::LatLng->value)
        ->and($jurisdiction?->locality?->value)->toBe('34.052200,-118.243700');
});

it('keeps the ZIP+4 key for states resolved by the boundary index', function () {
    $http = new Factory;
    $http->fake([
        'api.geocod.io/*' => $http->response([
            'results' => [
                [
                    'address_components' => ['country' => 'US', 'state_province' => 'KS'],
                    'location' => ['lat' => 39.1155, 'lng' => -94.6268],
                    'fields' => ['zip4' => ['zip9' => ['66101-3064']]],
                ],
            ],
        ]),
    ]);

    $jurisdiction = new GeocodioGeocoder($http, $this->geo, 'test-key', rooftop: true)
        ->locate(['line1' => '701 N 7th St', 'city' => 'Kansas City', 'country' => 'US']);

    expect($jurisdiction?->locality?->scheme)->toBe(LocalityScheme::Zip9->value)
        ->and($jurisdiction?->locality?->value)->toBe('66101-3064');
});
