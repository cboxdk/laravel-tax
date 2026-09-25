<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\LocalityScheme;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Geocoder\GeocodioGeocoder;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Territories\UsLocalStructure;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->source = $this->app->make(TaxRateSource::class);
});

/** A US place carrying a county locality, the way the geocoder attaches one. */
function atCounty(string $state, string $county): Jurisdiction
{
    return test()->geo->find(new CountryCode('US'), new SubdivisionCode($state))
        ->withLocality(new LocalityCode(
            new SubdivisionCode($state),
            LocalityScheme::County->value,
            $county,
        ));
}

// ---------------------------------------------------------------------------
// The rate itself
// ---------------------------------------------------------------------------

it('stacks the county surtax onto the state share in Florida', function (): void {
    $rate = $this->source->rateFor(atCounty('US-FL', 'Alachua County'), TaxClass::GeneralGoods);

    // 6% state + 1.5% county. Before the county resolved, this address priced at
    // the bare 6% state share — a 1.5-point under-charge on every Alachua sale.
    expect((string) $rate?->percentage)->toBe('7.5')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative);
});

it('reports the state and county shares separately so each can be remitted', function (): void {
    $rate = $this->source->rateFor(atCounty('US-FL', 'Alachua County'), TaxClass::GeneralGoods);

    expect($rate?->components)->toHaveCount(2)
        ->and($rate?->components[0]->level)->toBe(JurisdictionLevel::State)
        ->and((string) $rate?->components[0]->percentage)->toBe('6')
        ->and($rate?->components[1]->level)->toBe(JurisdictionLevel::County)
        ->and((string) $rate?->components[1]->percentage)->toBe('1.5')
        ->and($rate?->components[1]->name)->toBe('Alachua County');
});

it('treats a county that levies no surtax as an authoritative all-in rate', function (): void {
    $rate = $this->source->rateFor(atCounty('US-FL', 'Citrus County'), TaxClass::GeneralGoods);

    // Citrus levies 0%. That is not "we could not resolve a local rate" — it is a
    // resolved answer that happens to equal the state share, and the confidence
    // has to say so or the caller cannot tell the two apart.
    expect((string) $rate?->percentage)->toBe('6')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative);
});

it('resolves Philadelphia, where the city IS the county', function (): void {
    // The dataset carries Philadelphia as a CITY because that is its name, while a
    // geocoder returns "Philadelphia County". Coterminous, so the county resolves it.
    $rate = $this->source->rateFor(atCounty('US-PA', 'Philadelphia County'), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('8')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative);
});

it('resolves Allegheny alongside it without confusing the two', function (): void {
    $rate = $this->source->rateFor(atCounty('US-PA', 'Allegheny County'), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('7');
});

it('leaves the rest of Pennsylvania at the state rate, authoritatively', function (): void {
    // Only two Pennsylvania authorities exist. Everywhere else the 6% state rate is
    // the whole rate — but the county has to fail to match for that to be reached,
    // and an unmatched county is UNKNOWN, not zero. So this is the honest partial.
    $rate = $this->source->rateFor(atCounty('US-PA', 'Bucks County'), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('6')
        ->and($rate?->confidence)->toBe(Confidence::Derived);
});

it('adds the Hawaii county surcharge to the general excise rate', function (): void {
    $rate = $this->source->rateFor(atCounty('US-HI', 'Honolulu County'), TaxClass::GeneralGoods);

    // 4% GET + 0.5% county surcharge. This is the LEGAL rate on the seller's gross
    // receipts. The higher figure a Honolulu customer sees on a receipt is the
    // seller's gross-up to recover a tax that is itself taxable — a separate
    // question from what is owed, and deliberately not modelled here.
    expect((string) $rate?->percentage)->toBe('4.5');
});

// ---------------------------------------------------------------------------
// Virginia: cities that are not inside counties
// ---------------------------------------------------------------------------

it('resolves a Virginia county to its regional rate', function (): void {
    // 5.3% state (which already contains the mandatory statewide 1% local) plus
    // the Historic Triangle's 1.7%.
    $rate = $this->source->rateFor(atCounty('US-VA', 'James City County'), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('7')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative);
});

it('resolves a Virginia independent city, which sits in no county at all', function (): void {
    // Williamsburg is a city, and under Virginia law that means it is independent
    // of every county — a county-equivalent, not something inside one. The dataset
    // stores it bare; a geocoder says "Williamsburg City".
    $rate = $this->source->rateFor(atCounty('US-VA', 'Williamsburg City'), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('7');
});

it('tells Fairfax City and Fairfax County apart', function (string $given, string $expectedName): void {
    // The trap the ordered match exists for. Both are real Virginia authorities
    // over different ground, and comparing both names stripped would find two
    // matches and refuse — costing Fairfax its regional rate for no reason.
    $rate = $this->source->rateFor(atCounty('US-VA', $given), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('6')
        ->and($rate?->components[1]->name)->toBe($expectedName);
})->with([
    'the county' => ['Fairfax County', 'Fairfax County'],
    'the independent city' => ['Fairfax City', 'Fairfax'],
]);

it('leaves an unlisted Virginia locality at the statewide rate', function (): void {
    // Most of Virginia levies no regional addition, and 5.3% is genuinely the whole
    // rate there — but it is reached by the county failing to match, which is
    // "unknown", not "nothing applies". Derived says so honestly.
    $rate = $this->source->rateFor(atCounty('US-VA', 'Augusta County'), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('5.3')
        ->and($rate?->confidence)->toBe(Confidence::Derived);
});

// ---------------------------------------------------------------------------
// The name join, which is the fragile part
// ---------------------------------------------------------------------------

it('matches county names through punctuation and the governing-unit suffix', function (string $given, string $expected): void {
    $authorities = app(LocalAuthorityResolver::class)->authoritiesFor(atCounty('US-FL', $given));

    expect($authorities)->toBe(['us:FL', $expected]);
})->with([
    'exact' => ['Alachua County', 'us:FL:COUNTY-ALACHUA'],
    'suffix dropped' => ['Alachua', 'us:FL:COUNTY-ALACHUA'],
    'case folded' => ['ALACHUA COUNTY', 'us:FL:COUNTY-ALACHUA'],
    'hyphenated' => ['Miami-Dade County', 'us:FL:COUNTY-MIAMI-DADE'],
    'abbreviation with a period' => ['St. Johns County', 'us:FL:COUNTY-ST-JOHNS'],
]);

it('refuses a county the state does not carry rather than guessing a neighbour', function (): void {
    expect(app(LocalAuthorityResolver::class)->authoritiesFor(atCounty('US-FL', 'Nonesuch County')))->toBeNull();
});

it('falls back to the honest state rate when the county does not resolve', function (): void {
    $rate = $this->source->rateFor(atCounty('US-FL', 'Nonesuch County'), TaxClass::GeneralGoods);

    // Deny-by-default in the safe direction: a partial answer labelled partial,
    // never a zero local share stamped authoritative.
    expect((string) $rate?->percentage)->toBe('6')
        ->and($rate?->confidence)->toBe(Confidence::Derived);
});

// ---------------------------------------------------------------------------
// The claim the whole mechanism rests on
// ---------------------------------------------------------------------------

it('only claims county resolution where nothing can tax below the county', function (): void {
    $states = UsLocalStructure::countyResolvedStates();

    // South Carolina is the case this list exists to exclude: 46 of its 47
    // authorities are counties, but Myrtle Beach levies its own 1% Tourism
    // Development tax on top of Horry County's. Resolving only the county there
    // under-charges, and an under-charge is the one error a later refund cannot fix.
    expect($states)->not->toContain('US-SC')
        ->and($states)->toBe(['US-FL', 'US-PA', 'US-HI', 'US-VA']);
});

it('carries no authority below the county in any county-resolved state', function (): void {
    // The claim the list rests on: in these four states nothing sits under the
    // county line. A city record that is not coterminous with its county would make
    // county-only resolution an UNDER-charge, which is the error a refund cannot fix.
    $dataset = app(RegisterDataset::class);
    $coterminous = UsLocalStructure::coterminousCityCounties();
    $cityStates = UsLocalStructure::countyEquivalentCityStates();

    foreach (UsLocalStructure::countyResolvedStates() as $state) {
        $short = substr($state, 3);

        if (in_array($state, $cityStates, true)) {
            continue;   // every city there is a county-equivalent by law
        }

        foreach (array_keys($dataset->namesIn('us/'.$short)) as $code) {
            $segment = explode(':', $code)[2] ?? '';

            expect(str_starts_with($segment, 'CITY-') && ! in_array($state.':'.substr($segment, 5), $coterminous, true))
                ->toBeFalse("{$code} sits below the county line in {$state}");
        }
    }
});

// ---------------------------------------------------------------------------
// The geocoder end of it
// ---------------------------------------------------------------------------

it('attaches the county without the rooftop append being enabled', function (): void {
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

    expect($jurisdiction?->locality?->scheme)->toBe(LocalityScheme::County->value)
        ->and($jurisdiction?->locality?->value)->toBe('Alachua County');
});

it('does not attach a county in a state that needs finer resolution', function (): void {
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

it('keeps the state rate when the geocoder returns no county', function (): void {
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
