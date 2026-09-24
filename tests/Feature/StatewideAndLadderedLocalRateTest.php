<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\LocalityScheme;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Register\Reader\RateResolver;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterBoundaries;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\Testing\FakeRegister;

/*
 * Three ways a local share went missing from a stacked rate, each found by reading
 * the live register rather than the fixture, and each an under-charge that looked
 * entirely plausible on the invoice.
 */

function ladderStore(): string
{
    return test()->ladderStore;
}

function ladderRegister(): FakeRegister
{
    return FakeRegister::at(ladderStore());
}

function ladderRateFor(string $state, string $zip9, TaxClass $class)
{
    $layout = new StoreLayout(ladderStore());
    $dataset = new RegisterDataset($layout, new StorePointer($layout));
    $place = app(JurisdictionRepository::class)
        ->find(new CountryCode('US'), new SubdivisionCode($state))
        ->withLocality(new LocalityCode(new SubdivisionCode($state), LocalityScheme::Zip9->value, $zip9));

    return new RegisterRateSource(
        $dataset,
        new RateResolver,
        new RegisterBoundaries($layout, new StorePointer($layout)->current() ?? '', $dataset),
    )->rateFor($place, $class);
}

beforeEach(function (): void {
    $this->ladderStore = sys_get_temp_dir().'/cbox-tax-ladder-'.getmypid().'-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    $remove = function (string $directory) use (&$remove): void {
        foreach (is_dir($directory) ? (array) scandir($directory) : [] as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $remove($path) : @unlink($path);
        }

        @rmdir($directory);
    };

    $remove($this->ladderStore);
});

it('adds a local share the state levies everywhere, with no boundary file in sight', function (): void {
    // Virginia's grocery tax: 1%, levied across the whole state, so there is no city
    // or county to file it against and the register files it under `us:VA`.
    //
    // AND VIRGINIA PUBLISHES NO BOUNDARY FILE — it is not a Streamlined member, so
    // nothing ever resolves an authority set there. The first version of this fix sat
    // inside the stacking loop, which only runs when a set resolves, so it never ran
    // once for the one state it was written for. The test hid that by inventing a
    // boundary Virginia does not have. A share that applies everywhere in a state by
    // statute needs no address resolved to reach it.
    ladderRegister()
        ->rate('us:VA', '5.3')
        ->rate('us:VA', '0', 'exempt', 'goods.food')
        ->rate('us:VA', '1', 'local_component', 'goods.food')
        ->install();

    $groceries = ladderRateFor('US-VA', '23219-0001', TaxClass::Groceries);
    $general = ladderRateFor('US-VA', '23219-0001', TaxClass::GeneralGoods);

    // The state exempts food and the locality still takes its 1%.
    expect((string) $groceries?->percentage)->toBe('1')
        // ...and nothing else picks it up: the row is scoped to food.
        ->and((string) $general?->percentage)->toBe('5.3');
});

it('adds the statewide share on top of a resolved stack too', function (): void {
    ladderRegister()
        ->rate('us:VA', '5.3')
        ->rate('us:VA', '1', 'local_component', 'goods.food')
        ->rate('us:VA:COUNTY-041', '0.7', 'local_component')
        ->boundary('VA', '23219', ['state:51', 'county:041'])
        ->install();

    $rate = ladderRateFor('US-VA', '23219-0001', TaxClass::Groceries);

    expect((string) $rate?->percentage)->toBe('7')
        ->and(array_map(fn ($c): string => $c->level->value, $rate?->components ?? []))
        ->toBe(['state', 'county', 'local']);
});

it('reaches a local rate filed at a parent category from the leaf an invoice sells at', function (): void {
    // Tennessee's reduced local food rate is filed at `goods.food`; a shopping basket
    // is categorised `goods.food.basic`. Matching on exact equality reached neither,
    // and fell through to the city's untyped general rate — 2.75 where the ordinance
    // says 1. Over-charging, which is the quieter direction and no more correct.
    //
    // 11 322 local records in the live US register sit on `goods.food`.
    ladderRegister()
        ->rate('us:TN', '7')
        ->rate('us:TN:CITY-52006', '2.75', 'local_component')
        ->rate('us:TN:CITY-52006', '1', 'local_component', 'goods.food')
        ->boundary('TN', '37201', ['state:47', 'city:52006'])
        ->install();

    expect((string) ladderRateFor('US-TN', '37201-0001', TaxClass::Groceries)?->percentage)->toBe('8')
        // The leaf still beats the parent where the register files one.
        ->and((string) ladderRateFor('US-TN', '37201-0001', TaxClass::GeneralGoods)?->percentage)->toBe('9.75');
});

it('prefers a rate filed on the leaf over one filed on its parent', function (): void {
    ladderRegister()
        ->rate('us:TN', '7')
        ->rate('us:TN:CITY-52006', '2.75', 'local_component')
        ->rate('us:TN:CITY-52006', '1', 'local_component', 'goods.food')
        ->rate('us:TN:CITY-52006', '0.5', 'local_component', 'goods.food.basic')
        ->boundary('TN', '37201', ['state:47', 'city:52006'])
        ->install();

    expect((string) ladderRateFor('US-TN', '37201-0001', TaxClass::Groceries)?->percentage)->toBe('7.5');
});

function ladderRateByAuthority(string $state, string $authority)
{
    $layout = new StoreLayout(ladderStore());
    $dataset = new RegisterDataset($layout, new StorePointer($layout));
    $place = app(JurisdictionRepository::class)
        ->find(new CountryCode('US'), new SubdivisionCode($state))
        ->withLocality(new LocalityCode(new SubdivisionCode($state), LocalityScheme::Authority->value, $authority));

    return new RegisterRateSource(
        $dataset,
        new RateResolver,
        new RegisterBoundaries($layout, new StorePointer($layout)->current() ?? '', $dataset),
    )->rateFor($place, TaxClass::GeneralGoods);
}

it('completes the stack when a caller names one authority instead of an address', function (): void {
    // ONE AUTHORITY CODE IS NOT A STACK. `sst-fips:36000` is Kansas City, and pairing
    // it with the state gave 8.125% stamped Authoritative — missing Wyandotte
    // County's 1%, where the same address by ZIP+4 bills 9.125%. The boundary file
    // already held the answer: its `sets` table is every combination of authorities
    // that occurs in the state, and 36000 appears in exactly one of them.
    ladderRegister()
        ->rate('us:KS', '6.5')
        ->rate('us:KS:COUNTY-209', '1', 'local_component')
        ->rate('us:KS:CITY-36000', '1.625', 'local_component')
        ->boundary('KS', '66101', ['state:20', 'county:209', 'city:36000'])
        ->install();

    $rate = ladderRateByAuthority('US-KS', '36000');

    expect((string) $rate?->percentage)->toBe('9.125')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative)
        ->and(array_map(fn ($c): ?string => $c->code, $rate?->components ?? []))
        ->toContain('us:KS:COUNTY-209');
});

it('defers rather than guess when one authority sits in two different stacks', function (): void {
    // A city split across two counties. Every set containing it is a real stack and
    // nothing below an address distinguishes them, so there is no answer to give.
    // Returning the part they agree on would be a floor presented as a total — the
    // same defect one rung quieter — so this falls to the state share and FLAGS it,
    // which is what sends an operator to the address-level lookup.
    ladderRegister()
        ->rate('us:KS', '6.5')
        ->rate('us:KS:COUNTY-209', '1', 'local_component')
        ->rate('us:KS:COUNTY-005', '1', 'local_component')
        ->rate('us:KS:CITY-36000', '1.625', 'local_component')
        ->boundary('KS', '66101', ['state:20', 'county:209', 'city:36000'])
        ->boundary('KS', '66102', ['state:20', 'county:005', 'city:36000'])
        ->install();

    $rate = ladderRateByAuthority('US-KS', '36000');

    expect((string) $rate?->percentage)->toBe('6.5')
        ->and($rate?->confidence)->toBe(Confidence::Derived);
});

function ladderStateRate(string $state)
{
    $layout = new StoreLayout(ladderStore());
    $dataset = new RegisterDataset($layout, new StorePointer($layout));

    return new RegisterRateSource($dataset, new RateResolver)->rateFor(
        app(JurisdictionRepository::class)->find(new CountryCode('US'), new SubdivisionCode($state)),
        TaxClass::GeneralGoods,
    );
}

function ladderCountryRate(string $country, TaxClass $class = TaxClass::GeneralGoods)
{
    $layout = new StoreLayout(ladderStore());
    $dataset = new RegisterDataset($layout, new StorePointer($layout));

    return new RegisterRateSource($dataset, new RateResolver)
        ->rateFor(app(JurisdictionRepository::class)->find(new CountryCode($country)), $class);
}

it('does not answer for a country with a US state that shares its letters', function (): void {
    // `us:CA` is California and `ca:CA` is Canada. The register lists all fifty-four
    // states as regime members, and matching a member by its trailing ISO alone made
    // a US state the answer for twenty-five countries — Gabon priced at Georgia's
    // rate, Israel at Illinois', Canada at California's, each stamped Authoritative.
    // Which one won came down to the order regimes happened to appear in.
    //
    // The register labels every jurisdiction's level, and a `state` is never the
    // answer to what a COUNTRY charges.
    ladderRegister()
        ->rate('us:CA', '7.25')
        ->rate('ca:CA', '5')
        ->rate('us:GA', '4')
        ->rate('africa:GA', '18')
        ->install();

    expect((string) ladderCountryRate('CA')?->percentage)->toBe('5')
        ->and((string) ladderCountryRate('GA')?->percentage)->toBe('18');
});

it('charges a seller-borne tax the statute lets the seller pass on', function (): void {
    // A transaction privilege tax, a general excise tax and a gross receipts tax are
    // all legally the SELLER's, and all are passed on as a matter of course — it is
    // why a Honolulu receipt shows the rate at all. Reading `borneBy` alone and
    // stopping there left Arizona, Hawaii, New Mexico, Guam, the US Virgin Islands,
    // Malaysia, Aruba and Curaçao with no rate, so the engine refused on every
    // supply into eight jurisdictions.
    ladderRegister()
        ->rate('us:NM', '4.875', extra: ['borneBy' => 'supplier', 'mayBePassedOn' => true])
        ->install();

    expect((string) ladderRateFor('US-NM', '87501-0001', TaxClass::GeneralGoods)?->percentage)->toBe('4.875');
});

it('still refuses to charge a levy the seller may not pass on', function (): void {
    // The case the filter was written for, and the only one it should catch: a
    // digital services tax is a levy on the supplier's turnover that the statute does
    // NOT let them recover on the invoice. Summing it into a cart overcharges the
    // customer and under-declares the liability in one step.
    ladderRegister()
        ->rate('us:NM', '2', extra: ['borneBy' => 'supplier', 'mayBePassedOn' => false])
        ->install();

    expect(ladderRateFor('US-NM', '87501-0001', TaxClass::GeneralGoods))->toBeNull();
});

it('takes the headline band, not a category-scoped rate that happens to come first', function (): void {
    // Arizona files a per-unit standard rate on telecommunications ahead of its 5.6%
    // band. Taking the first `standard` row returned that one, which has no
    // percentage at all, so the state resolved to nothing and the engine refused.
    ladderRegister()
        ->rate('us:AZ', '0', 'standard', 'services.telecom', extra: ['basis' => 'per_unit', 'perUnit' => ['amount' => '0.02', 'currency' => 'USD', 'per' => 'line'], 'percentage' => null])
        ->rate('us:AZ', '5.6')
        ->install();

    expect((string) ladderRateFor('US-AZ', '85001-0001', TaxClass::GeneralGoods)?->percentage)->toBe('5.6');
});

it('prices a bracket schedule at its own per-dollar rate, flagged', function (): void {
    // Alabama, Idaho, Maryland and Pennsylvania publish a table in cents instead of a
    // percentage — 11 to 17 cents is one cent of tax, 18 to 34 is two — with a
    // per-dollar figure for anything above a dollar. $0.06 per dollar is six per
    // cent, and it sits in the data rather than being inferred.
    //
    // It used to be refused, which priced NOTHING in four states. A rate within a
    // cent of the table, flagged as being within a cent, is worth more to a shop than
    // an exception.
    ladderRegister()
        ->rate('us:PA', '0', extra: [
            'basis' => 'bracket',
            'percentage' => null,
            'brackets' => [
                'rows' => [['from' => '0.00', 'upTo' => '0.10', 'tax' => '0.00']],
                'above' => ['perWholeUnit' => ['amount' => '0.06', 'currency' => 'USD', 'per' => 'dollar']],
            ],
        ])
        ->install();

    // Asked at state level, so the flag the assessment carries is the bracket one.
    // With an address it would be `NoLocalResolution` instead — Pennsylvania has
    // locals and publishes no boundary file — and a rate carries one limit, the
    // nearest thing to act on.
    $rate = ladderStateRate('US-PA');

    expect((string) $rate?->percentage)->toBe('6')
        ->and($rate?->confidence)->toBe(Confidence::Derived)
        ->and($rate?->limitedBy)->toBe(RateLimit::BracketSchedule);
});

it('still refuses a per-unit amount that has no percentage to give', function (): void {
    // A schedule expressed per litre or per item is a different kind of tax. There is
    // no percentage in it, and inventing one is exactly what this source does not do.
    ladderRegister()
        ->rate('us:PA', '0', extra: [
            'basis' => 'per_unit',
            'percentage' => null,
            'perUnit' => ['amount' => '0.25', 'currency' => 'USD', 'per' => 'litre'],
        ])
        ->install();

    expect(ladderStateRate('US-PA'))->toBeNull();
});

it('does not add an untyped statewide share to a category the jurisdiction zero-rates', function (): void {
    // Brazil files a 0.1% IBS component with NO category — the general local share —
    // beside zero-rated rows for basic food, books and newspapers. Adding it to those
    // billed 0.1% on a loaf of bread the statute exempts.
    //
    // Virginia is the case this must not swallow, and the test above is that one: its
    // 1% is filed AT `goods.food`, next to the state's own exemption on the same
    // category. Naming the category is the register saying the locality levies there
    // whatever the state does.
    ladderRegister()
        ->rate('us:VA', '0.9')
        ->rate('us:VA', '0.1', 'local_component')
        ->rate('us:VA', '0', 'zero', 'goods.food.basic')
        ->install();

    expect((string) ladderStateRate('US-VA')?->percentage)->toBe('1')
        ->and((string) ladderRateFor('US-VA', '23219-0001', TaxClass::Groceries)?->percentage)->toBe('0');
});

it('marks a rate reached by climbing to a rung whose conditions narrow it', function (): void {
    // The United Kingdom zero-rates food and EXCLUDES confectionery and catering from
    // that zero. Asked about sweets the engine climbs to `goods.food`, finds 0%, and
    // returns the exact figure the exclusion exists to deny — sweets are standard
    // rated at 20%.
    //
    // The register states a condition as prose: the statute's own words and a short
    // label, never a link to a category. So a consumer can read THAT a rate is
    // narrowed and cannot read what it was narrowed to. The figure is still the best
    // one available, so it is returned and marked rather than withheld.
    ladderRegister()
        ->rate('eu:GB', '20')
        ->rate('eu:GB', '0', 'zero', 'goods.food', extra: ['conditions' => [
            ['kind' => 'excludes', 'says' => 'except a supply in the course of catering', 'names' => 'catering and the excepted items'],
        ]])
        ->install();

    $sweets = ladderCountryRate('GB', TaxClass::Candy);

    expect((string) $sweets?->percentage)->toBe('0')
        ->and($sweets?->confidence)->toBe(Confidence::Derived)
        ->and($sweets?->limitedBy)->toBe(RateLimit::ConditionsUnevaluated)
        // Closable in general — a commodity code or a fact settles every typed
        // condition — though this one is prose only; CatalogueAudit tells them apart.
        ->and($sweets?->limitedBy->callerCanClose())->toBeTrue();
});

it('leaves a rate asked for at its own rung alone, conditions and all', function (): void {
    // Ireland zero-rates books and excludes newspapers from that zero. Asked about a
    // book, the exclusion is not about you — and flagging it would put a caveat on
    // 123 of the register's answers to buy a warning on 25.
    ladderRegister()
        ->rate('eu:IE', '23')
        ->rate('eu:IE', '0', 'zero', 'goods.publications.book', extra: ['conditions' => [
            ['kind' => 'excludes', 'says' => 'but excluding newspapers, periodicals and brochures', 'names' => 'newspapers and periodicals'],
        ]])
        ->install();

    $book = ladderCountryRate('IE', TaxClass::Book);

    expect((string) $book?->percentage)->toBe('0')
        ->and($book?->confidence)->toBe(Confidence::Authoritative)
        ->and($book?->limitedBy)->toBeNull();
});

it('flags a bare ZIP that is split between authority sets, and not one that is uniform', function (): void {
    // A ZIP is a mail route, not a tax boundary. Washington's 98001 holds Federal Way
    // and Auburn beside unincorporated King County; asked from the five digits alone,
    // the store returned the set 98001-0000 falls in, marked certain.
    ladderRegister()
        ->rate('us:WA', '6.5')
        ->rate('us:WA:DISTRICT-L1702', '4', 'local_component')
        ->rate('us:WA:CITY-FEDERAL-WAY', '3.9', 'local_component')
        ->boundary('WA', '98001', ['state:WA', 'district:L1702'], '0000', '1399')
        ->boundary('WA', '98001', ['state:WA', 'city:FEDERAL-WAY'], '1400', '9999')
        ->boundary('WA', '98002', ['state:WA', 'district:L1702'])
        ->install();

    $split = ladderRateFor('US-WA', '98001', TaxClass::GeneralGoods);
    $address = ladderRateFor('US-WA', '98001-1400', TaxClass::GeneralGoods);
    $uniform = ladderRateFor('US-WA', '98002', TaxClass::GeneralGoods);

    expect($split?->limitedBy)->toBe(RateLimit::PostcodeSpansLocalities)
        ->and($split?->confidence)->toBe(Confidence::Derived)
        // The ZIP+4 narrows it to one span, and that answer is exact.
        ->and((string) $address?->percentage)->toBe('10.4')
        ->and($address?->limitedBy)->toBeNull()
        ->and($address?->confidence)->toBe(Confidence::Authoritative)
        // A ZIP with one set is decided by its five digits: no caveat.
        ->and($uniform?->limitedBy)->toBeNull()
        ->and($uniform?->confidence)->toBe(Confidence::Authoritative);

    $layout = new StoreLayout(ladderStore());
    $boundaries = new RegisterBoundaries($layout, new StorePointer($layout)->current() ?? '', new RegisterDataset($layout, new StorePointer($layout)));

    expect($boundaries->zipIsUniform('WA', '98001'))->toBeFalse()
        ->and($boundaries->zipIsUniform('WA', '98002'))->toBeTrue()
        ->and($boundaries->zipIsUniform('WA', '99999'))->toBeNull()
        ->and($boundaries->zipIsUniform('TX', '78701'))->toBeNull();
});

/**
 * A square polygon, as a GeoJSON ring in [lng, lat] order.
 *
 * @return list<list<list<float>>>
 */
function square(float $lng, float $lat, float $half): array
{
    return [[[$lng - $half, $lat - $half], [$lng + $half, $lat - $half], [$lng + $half, $lat + $half], [$lng - $half, $lat + $half], [$lng - $half, $lat - $half]]];
}

function pointRateFor(string $state, float $lat, float $lng, ?string $on = null)
{
    $layout = new StoreLayout(ladderStore());
    $dataset = new RegisterDataset($layout, new StorePointer($layout));
    $place = app(JurisdictionRepository::class)
        ->find(new CountryCode('US'), new SubdivisionCode($state))
        ->withLocality(new LocalityCode(new SubdivisionCode($state), LocalityScheme::LatLng->value, $lat.','.$lng));

    return new RegisterRateSource($dataset, new RateResolver, new RegisterBoundaries($layout, new StorePointer($layout)->current() ?? '', $dataset))
        ->rateFor($place, TaxClass::GeneralGoods, $on === null ? null : new DateTimeImmutable($on));
}

function countyRateFor(string $state, string $county)
{
    $layout = new StoreLayout(ladderStore());
    $dataset = new RegisterDataset($layout, new StorePointer($layout));
    $place = app(JurisdictionRepository::class)
        ->find(new CountryCode('US'), new SubdivisionCode($state))
        ->withLocality(new LocalityCode(new SubdivisionCode($state), LocalityScheme::County->value, $county));

    return new RegisterRateSource($dataset, new RateResolver, new RegisterBoundaries($layout, new StorePointer($layout)->current() ?? '', $dataset))
        ->rateFor($place, TaxClass::GeneralGoods);
}

it('adds the state share to a polygon answer in a state that files local components', function (): void {
    // Texas's polygon layers are cities, combined areas, transit, special purpose
    // districts and counties — no state. Its local rates are COMPONENTS, so a point
    // answered from polygons alone summed the locals and left out the 6.25% state
    // share: an Austin address at 2%, authoritative. California and New Mexico file
    // all-in COMBINED totals, which is why the gap never showed there.
    //
    // A combined area REPLACES the city, district or county it combines (geometry
    // format 3): summing every polygon over a point in Bee Cave gave 5.5% where 2% is
    // due.
    ladderRegister()
        ->rate('us:TX', '6.25')
        ->rate('us:TX:COUNTY-2227', '0.5', 'local_component')->named('us:TX:COUNTY-2227', 'Travis County')
        ->rate('us:TX:CITY-2227001', '1', 'local_component')->named('us:TX:CITY-2227001', 'Austin')
        ->rate('us:TX:TRANSIT-3227999', '1', 'local_component')->named('us:TX:TRANSIT-3227999', 'Capital Metro')
        ->rate('us:TX:COMBINED-5227500', '2', 'local_component')->named('us:TX:COMBINED-5227500', 'Bee Cave combined area')
        ->geometry('TX', [
            ['type' => 'Feature', 'properties' => ['authority' => 'us:TX:COUNTY-2227', 'level' => 'county', 'name' => 'Travis'], 'geometry' => ['type' => 'Polygon', 'coordinates' => square(-97.8, 30.3, 0.5)]],
            ['type' => 'Feature', 'properties' => ['authority' => 'us:TX:CITY-2227001', 'level' => 'city', 'name' => 'Austin'], 'geometry' => ['type' => 'Polygon', 'coordinates' => square(-97.75, 30.27, 0.1)]],
            ['type' => 'Feature', 'properties' => ['authority' => 'us:TX:TRANSIT-3227999', 'level' => 'transit', 'name' => 'Capital Metro'], 'geometry' => ['type' => 'Polygon', 'coordinates' => square(-97.75, 30.27, 0.3)]],
            ['type' => 'Feature', 'properties' => ['authority' => 'us:TX:COMBINED-5227500', 'level' => 'combined', 'name' => 'Bee Cave', 'replaces' => ['us:TX:COUNTY-2227', 'us:TX:TRANSIT-3227999']], 'geometry' => ['type' => 'Polygon', 'coordinates' => square(-97.95, 30.31, 0.02)]],
        ])
        ->install();

    $austin = pointRateFor('US-TX', 30.27, -97.75);
    $beeCave = pointRateFor('US-TX', 30.31, -97.95);

    // 6.25 state + 0.5 county + 1 city + 1 transit.
    expect((string) $austin?->percentage)->toBe('8.75')
        ->and($austin?->confidence)->toBe(Confidence::Authoritative)
        // 6.25 state + 2 combined; the county and transit it replaces are dropped.
        ->and((string) $beeCave?->percentage)->toBe('8.25');
});

it('falls back to the state share, flagged, when a polygon file cannot be read', function (): void {
    // A `replaces` that is not a list of codes is refused by the resolver. Refused
    // loudly inside a price, it stopped every Texas sale; deferred, it is the state
    // share with the gap named — short, but honest.
    ladderRegister()
        ->rate('us:TX', '6.25')
        ->rate('us:TX:CITY-2227001', '1', 'local_component')
        ->geometry('TX', [
            ['type' => 'Feature', 'properties' => ['authority' => 'us:TX:CITY-2227001', 'level' => 'city', 'replaces' => 'not-a-list'], 'geometry' => ['type' => 'Polygon', 'coordinates' => square(-97.75, 30.27, 0.1)]],
        ])
        ->install();

    $rate = pointRateFor('US-TX', 30.27, -97.75);

    expect((string) $rate?->percentage)->toBe('6.25')
        ->and($rate?->limitedBy)->toBe(RateLimit::NoLocalResolution);
});

it('reads a point outside every polygon as no local tax only where the register says so, on the supply date', function (): void {
    // "Outside every feature" is not knowledge until the register says its layers
    // leave no levying ground out. California's and New Mexico's do; Texas's do not yet
    // — a district in force with no polygon, and districts starting 1 October before
    // the layer carries them. So the claim is dated, and read on the supply date.
    $city = [['type' => 'Feature', 'properties' => ['authority' => 'us:TX:CITY-2227001', 'level' => 'city'], 'geometry' => ['type' => 'Polygon', 'coordinates' => square(-97.75, 30.27, 0.1)]]];
    $outside = [31.5, -100.5];

    $install = function (array|string|null $absence) use ($city): void {
        ladderRegister()
            ->rate('us:TX', '6.25', from: '1990-01-01')
            ->rate('us:TX:CITY-2227001', '1', 'local_component', from: '1990-01-01')
            ->geometry('TX', $city)
            ->usLocal('TX', absence: $absence)
            ->install();
    };

    // Nothing blocks it: no polygon means no local tax, and the state share is the
    // whole rate.
    $install([]);
    $none = pointRateFor('US-TX', ...$outside);
    expect((string) $none?->percentage)->toBe('6.25')
        ->and($none?->limitedBy)->toBeNull()
        ->and($none?->confidence)->toBe(Confidence::Authoritative);

    // A district with no polygon, in force from 1 October: before it, none; from it, unresolved.
    $install([['code' => 'us:TX:DISTRICT-5070559', 'from' => '2026-10-01', 'until' => null]]);
    expect(pointRateFor('US-TX', ...[...$outside, '2026-09-30'])?->limitedBy)->toBeNull()
        ->and(pointRateFor('US-TX', ...[...$outside, '2026-10-01'])?->limitedBy)->toBe(RateLimit::NoLocalResolution);

    // Known only from a floor: blocks every earlier date too.
    $install([['code' => 'us:TX:DISTRICT-6246610', 'from' => null, 'until' => null]]);
    expect(pointRateFor('US-TX', ...[...$outside, '2020-01-01'])?->limitedBy)->toBe(RateLimit::NoLocalResolution);

    // "unknown", or a release that says nothing: unresolved, as before the field.
    $install('unknown');
    expect(pointRateFor('US-TX', ...$outside)?->limitedBy)->toBe(RateLimit::NoLocalResolution);
    $install(null);
    expect(pointRateFor('US-TX', ...$outside)?->limitedBy)->toBe(RateLimit::NoLocalResolution);
});

it('resolves by county name where the register says a state needs no more, not by a list in the engine', function (): void {
    // Tennessee is on no hardcoded list. Told that a local answer there needs only the
    // county, the county's name resolves it; told nothing, it does not.
    $install = function (?string $needs): void {
        ladderRegister()
            ->rate('us:TN', '7')
            ->rate('us:TN:COUNTY-SHELBY', '2.25', 'local_component')->named('us:TN:COUNTY-SHELBY', 'Shelby County')
            ->usLocal('TN', needs: $needs)
            ->install();
    };

    $install('county');
    $shelby = countyRateFor('US-TN', 'Shelby County');
    expect((string) $shelby?->percentage)->toBe('9.25')
        ->and($shelby?->confidence)->toBe(Confidence::Authoritative);

    $install(null);
    expect(countyRateFor('US-TN', 'Shelby County')?->limitedBy)->toBe(RateLimit::NoLocalResolution);

    // And the data overrides the engine's list the other way: Florida told it needs
    // an address is no longer answered from the county name.
    ladderRegister()
        ->rate('us:FL', '6')
        ->rate('us:FL:COUNTY-MIAMI-DADE', '1', 'local_component')->named('us:FL:COUNTY-MIAMI-DADE', 'Miami-Dade County')
        ->usLocal('FL', needs: 'address')
        ->install();
    expect(countyRateFor('US-FL', 'Miami-Dade County')?->limitedBy)->toBe(RateLimit::NoLocalResolution);
});

it('matches a county the register names formally, and never the state that shares its name', function (): void {
    // From release 293 Hawaii's counties are named as their charters name them — "City
    // and County of Honolulu", "County of Hawaii" — where a geocoder says "Honolulu
    // County" and "Hawaii County". Matching stripped only a trailing unit word, so
    // neither resolved and Honolulu lost its 0.5% surcharge. And the STATE is named
    // "Hawaii" too: counted as a candidate, "Hawaii County" would match two and refuse.
    ladderRegister()
        ->rate('us:HI', '4')->named('us:HI', 'Hawaii')
        ->rate('us:HI:COUNTY-HONOLULU', '0.5', 'local_component')->named('us:HI:COUNTY-HONOLULU', 'City and County of Honolulu')
        ->rate('us:HI:COUNTY-HAWAII', '0.5', 'local_component')->named('us:HI:COUNTY-HAWAII', 'County of Hawaii')
        ->usLocal('HI', needs: 'county')
        ->install();

    $honolulu = countyRateFor('US-HI', 'Honolulu County');
    $hawaii = countyRateFor('US-HI', 'Hawaii County');

    expect((string) $honolulu?->percentage)->toBe('4.5')
        ->and($honolulu?->confidence)->toBe(Confidence::Authoritative)
        ->and((string) $hawaii?->percentage)->toBe('4.5')
        ->and(collect($hawaii?->components ?? [])->pluck('code')->all())->toContain('us:HI:COUNTY-HAWAII');
});

it('does not call a stack authoritative when the state share it stands on is not', function (): void {
    // Pennsylvania publishes a bracket table, so its 6% is the table's per-dollar rate
    // and flagged. Stacked with a county share, the total kept the flag and called
    // itself authoritative anyway — two answers to one question.
    ladderRegister()
        ->rate('us:PA', '6', extra: ['basis' => 'bracket', 'percentage' => null, 'brackets' => ['above' => ['perWholeUnit' => ['amount' => '0.06', 'currency' => 'USD', 'per' => 'dollar']]]])
        ->rate('us:PA:COUNTY-ALLEGHENY', '1', 'local_component')->named('us:PA:COUNTY-ALLEGHENY', 'Allegheny County')
        ->usLocal('PA', needs: 'county')
        ->install();

    $allegheny = countyRateFor('US-PA', 'Allegheny County');

    expect((string) $allegheny?->percentage)->toBe('7')
        ->and($allegheny?->limitedBy)->toBe(RateLimit::BracketSchedule)
        ->and($allegheny?->confidence)->toBe(Confidence::Derived);
});

it('does not read a polygon that carries no rate as no local tax', function (): void {
    // Release 294 draws Haines Borough as a feature with no rate of its own: three
    // codes levy inside it and no state layer draws them, so the borough stands for
    // all three (`contains`). A point there has local tax the store cannot price —
    // the state share, flagged — while a point outside every polygon, with nothing
    // left in blockedBy, is Anchorage: no local tax, and certain of it.
    ladderRegister()
        ->rate('us:AK', '0', from: '1990-01-01')
        ->rate('us:AK:CITY-800036', '5.5', 'local_component', from: '1990-01-01')
        ->geometry('AK', [
            ['type' => 'Feature', 'properties' => ['authority' => 'us:AK:COUNTY-HAINES-BOROUGH', 'level' => 'county', 'contains' => ['us:AK:COUNTY-800032', 'us:AK:COUNTY-800034', 'us:AK:CITY-800036']], 'geometry' => ['type' => 'Polygon', 'coordinates' => square(-135.45, 59.24, 0.5)]],
        ])
        ->usLocal('AK', absence: [])
        ->install();

    $haines = pointRateFor('US-AK', 59.24, -135.45);
    $anchorage = pointRateFor('US-AK', 61.22, -149.9);

    expect($haines?->limitedBy)->toBe(RateLimit::NoLocalResolution)
        ->and($haines?->confidence)->not->toBe(Confidence::Authoritative)
        ->and((string) $anchorage?->percentage)->toBe('0')
        ->and($anchorage?->limitedBy)->toBeNull()
        ->and($anchorage?->confidence)->toBe(Confidence::Authoritative);
});
