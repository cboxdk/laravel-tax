<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Geocoder\GeocodioGeocoder;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->dataset = new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/us-tax-dataset',
    );
    $this->source = new UsTaxDatasetRateSource($this->dataset);
});

/** A US place carrying a county locality, the way the geocoder attaches one. */
function atCounty(string $state, string $county): Jurisdiction
{
    return test()->geo->find(new CountryCode('US'), new SubdivisionCode($state))
        ->withLocality(new LocalityCode(
            new SubdivisionCode($state),
            UsTaxDatasetRateSource::COUNTY_SCHEME,
            $county,
        ));
}

// ---------------------------------------------------------------------------
// The rate itself
// ---------------------------------------------------------------------------

it('stacks the county surtax onto the state share in Florida', function () {
    $rate = $this->source->rateFor(atCounty('US-FL', 'Alachua County'), TaxClass::GeneralGoods);

    // 6% state + 1.5% county. Before the county resolved, this address priced at
    // the bare 6% state share — a 1.5-point under-charge on every Alachua sale.
    expect((string) $rate?->percentage)->toBe('7.5')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative);
});

it('reports the state and county shares separately so each can be remitted', function () {
    $rate = $this->source->rateFor(atCounty('US-FL', 'Alachua County'), TaxClass::GeneralGoods);

    expect($rate?->components)->toHaveCount(2)
        ->and($rate?->components[0]->level)->toBe(JurisdictionLevel::State)
        ->and((string) $rate?->components[0]->percentage)->toBe('6')
        ->and($rate?->components[1]->level)->toBe(JurisdictionLevel::County)
        ->and((string) $rate?->components[1]->percentage)->toBe('1.5')
        ->and($rate?->components[1]->name)->toBe('Alachua County');
});

it('treats a county that levies no surtax as an authoritative all-in rate', function () {
    $rate = $this->source->rateFor(atCounty('US-FL', 'Citrus County'), TaxClass::GeneralGoods);

    // Citrus levies 0%. That is not "we could not resolve a local rate" — it is a
    // resolved answer that happens to equal the state share, and the confidence
    // has to say so or the caller cannot tell the two apart.
    expect((string) $rate?->percentage)->toBe('6')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative);
});

it('resolves Philadelphia, where the city IS the county', function () {
    // The dataset carries Philadelphia as a CITY because that is its name, while a
    // geocoder returns "Philadelphia County". Coterminous, so the county resolves it.
    $rate = $this->source->rateFor(atCounty('US-PA', 'Philadelphia County'), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('8')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative);
});

it('resolves Allegheny alongside it without confusing the two', function () {
    $rate = $this->source->rateFor(atCounty('US-PA', 'Allegheny County'), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('7');
});

it('leaves the rest of Pennsylvania at the state rate, authoritatively', function () {
    // Only two Pennsylvania authorities exist. Everywhere else the 6% state rate is
    // the whole rate — but the county has to fail to match for that to be reached,
    // and an unmatched county is UNKNOWN, not zero. So this is the honest partial.
    $rate = $this->source->rateFor(atCounty('US-PA', 'Bucks County'), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('6')
        ->and($rate?->confidence)->toBe(Confidence::Derived);
});

it('adds the Hawaii county surcharge to the general excise rate', function () {
    $rate = $this->source->rateFor(atCounty('US-HI', 'Honolulu County'), TaxClass::GeneralGoods);

    // 4% GET + 0.5% county surcharge. This is the LEGAL rate on the seller's gross
    // receipts. The higher figure a Honolulu customer sees on a receipt is the
    // seller's gross-up to recover a tax that is itself taxable — a separate
    // question from what is owed, and deliberately not modelled here.
    expect((string) $rate?->percentage)->toBe('4.5');
});

// ---------------------------------------------------------------------------
// The name join, which is the fragile part
// ---------------------------------------------------------------------------

it('matches county names through punctuation and the governing-unit suffix', function (string $given, string $expected) {
    expect($this->dataset->localCodeForCounty('US-FL', $given))->toBe($expected);
})->with([
    'exact' => ['Alachua County', 'US-FL:Alachua County'],
    'suffix dropped' => ['Alachua', 'US-FL:Alachua County'],
    'case folded' => ['ALACHUA COUNTY', 'US-FL:Alachua County'],
    'hyphenated' => ['Miami-Dade County', 'US-FL:Miami-Dade County'],
    'abbreviation with a period' => ['St. Johns County', 'US-FL:St. Johns County'],
    'same name, no period' => ['St Johns County', 'US-FL:St. Johns County'],
]);

it('refuses a county the state does not carry rather than guessing a neighbour', function () {
    expect($this->dataset->localCodeForCounty('US-FL', 'Nonesuch County'))->toBeNull();
});

it('falls back to the honest state rate when the county does not resolve', function () {
    $rate = $this->source->rateFor(atCounty('US-FL', 'Nonesuch County'), TaxClass::GeneralGoods);

    // Deny-by-default in the safe direction: a partial answer labelled partial,
    // never a zero local share stamped authoritative.
    expect((string) $rate?->percentage)->toBe('6')
        ->and($rate?->confidence)->toBe(Confidence::Derived);
});

// ---------------------------------------------------------------------------
// The claim the whole mechanism rests on
// ---------------------------------------------------------------------------

it('only claims county resolution where nothing can tax below the county', function () {
    $states = UsTaxDatasetRateSource::countyResolvedStates();

    // South Carolina is the case this list exists to exclude: 46 of its 47
    // authorities are counties, but Myrtle Beach levies its own 1% Tourism
    // Development tax on top of Horry County's. Resolving only the county there
    // under-charges, and an under-charge is the one error a later refund cannot fix.
    expect($states)->not->toContain('US-SC')
        ->and($states)->toBe(['US-FL', 'US-PA', 'US-HI']);
});

it('carries no authority below the county in any county-resolved state', function () {
    $rates = json_decode(
        (string) file_get_contents(dirname(__DIR__).'/Fixtures/us-tax-dataset/by-section/rates.json'),
        true,
    );

    $coterminous = UsTaxDatasetRateSource::coterminousCityCounties();

    foreach (UsTaxDatasetRateSource::countyResolvedStates() as $state) {
        foreach ($rates['states'][$state]['local'] ?? [] as $code => $records) {
            foreach ($records as $record) {
                expect($record['level'] === 'county' || in_array($code, $coterminous, true))
                    ->toBeTrue("{$code} taxes below the county line — {$state} cannot be county-resolved.");
            }
        }
    }
});

// ---------------------------------------------------------------------------
// The geocoder end of it
// ---------------------------------------------------------------------------

it('attaches the county without the rooftop append being enabled', function () {
    Http::fake(['*' => Http::response(['results' => [[
        'address_components' => ['country' => 'US', 'state_province' => 'FL', 'county' => 'Alachua County'],
    ]]])]);

    // rooftop: false. The county costs no append and is exact where it applies, so
    // gating it behind the opt-in would leave Florida under-charging for nothing.
    $geocoder = new GeocodioGeocoder(
        $this->app->make(Factory::class),
        $this->geo,
        'test-key',
        rooftop: false,
    );

    $jurisdiction = $geocoder->locate(['line1' => '1 Main St', 'city' => 'Gainesville', 'state' => 'FL', 'country' => 'US']);

    expect($jurisdiction?->locality?->scheme)->toBe(UsTaxDatasetRateSource::COUNTY_SCHEME)
        ->and($jurisdiction?->locality?->value)->toBe('Alachua County');
});

it('does not attach a county in a state that needs finer resolution', function () {
    Http::fake(['*' => Http::response(['results' => [[
        'address_components' => ['country' => 'US', 'state_province' => 'KS', 'county' => 'Wyandotte County'],
    ]]])]);

    // Kansas has a boundary index and authorities below the county — Kansas City
    // pays the county AND the city. A county locality there would resolve to the
    // county alone and lose the city's 1.625%, stamped authoritative.
    $geocoder = new GeocodioGeocoder(
        $this->app->make(Factory::class),
        $this->geo,
        'test-key',
        rooftop: false,
    );

    expect($geocoder->locate(['line1' => '701 N 7th St', 'state' => 'KS', 'country' => 'US'])?->locality)->toBeNull();
});

it('keeps the state rate when the geocoder returns no county', function () {
    Http::fake(['*' => Http::response(['results' => [[
        'address_components' => ['country' => 'US', 'state_province' => 'FL'],
    ]]])]);

    $geocoder = new GeocodioGeocoder(
        $this->app->make(Factory::class),
        $this->geo,
        'test-key',
        rooftop: false,
    );

    expect($geocoder->locate(['line1' => '1 Main St', 'state' => 'FL', 'country' => 'US'])?->locality)->toBeNull();
});
