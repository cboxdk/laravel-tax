<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\LocalityScheme;
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

function ladderCountryRate(string $country)
{
    $layout = new StoreLayout(ladderStore());
    $dataset = new RegisterDataset($layout, new StorePointer($layout));

    return new RegisterRateSource($dataset, new RateResolver)
        ->rateFor(app(JurisdictionRepository::class)->find(new CountryCode($country)), TaxClass::GeneralGoods);
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
