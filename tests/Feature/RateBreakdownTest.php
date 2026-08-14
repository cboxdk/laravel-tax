<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\RateComponentsDoNotReconcile;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\RateBand;
use Cbox\Tax\ValueObjects\RateComponent;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxBreakdown;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->dataset = new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/us-tax-dataset',
    );
});

/** A US place, optionally at a rooftop locality. */
function breakdownPlace(string $state, ?LocalityCode $locality = null): Jurisdiction
{
    $j = test()->geo->find(new CountryCode('US'), new SubdivisionCode($state));

    return $locality === null ? $j : $j->withLocality($locality);
}

/** A ZIP+4 locality, the postal key the boundary index expands into authorities. */
function zip9(string $state, string $zip): LocalityCode
{
    return new LocalityCode(new SubdivisionCode($state), UsTaxDatasetRateSource::ZIP9_SCHEME, $zip);
}

/** A domestic US B2C supply the seller is registered for, at the given place. */
function breakdownQuery(
    Jurisdiction $place,
    string $amount = '100.00',
    Pricing $pricing = Pricing::Exclusive,
    TaxClass $category = TaxClass::GeneralGoods,
): TaxQuery {
    $state = $place->subdivision;

    return new TaxQuery(
        amount: Money::of($amount, 'USD'),
        pricing: $pricing,
        place: $place,
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), $state),
        ]),
        category: $category,
    );
}

/** The calculator wired to the dataset rate source, which decomposes what it stacks. */
function datasetCalculator(UsTaxDataset $dataset): DefaultTaxCalculator
{
    return new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(new StaticProductTaxability),
        new UsTaxDatasetRateSource($dataset),
    );
}

// ---- The reconcile invariant --------------------------------------------

it('accepts components that sum to the rate', function () {
    $rate = new TaxRate('9.125', RateKind::Standard, 'test', components: [
        new RateComponent(JurisdictionLevel::State, '6.5'),
        new RateComponent(JurisdictionLevel::County, '1'),
        new RateComponent(JurisdictionLevel::City, '1.625'),
    ]);

    expect($rate->hasComponents())->toBeTrue()
        ->and($rate->components)->toHaveCount(3);
});

it('refuses components that do not sum to the rate', function () {
    // The city share left out: the split would under-remit by 1.625 points while
    // looking exactly as authoritative as a correct one.
    new TaxRate('9.125', components: [
        new RateComponent(JurisdictionLevel::State, '6.5'),
        new RateComponent(JurisdictionLevel::County, '1'),
    ]);
})->throws(RateComponentsDoNotReconcile::class, 'sum to 7.5%');

it('reconciles numerically, so scale is presentation only', function () {
    $rate = new TaxRate('7.25', components: [new RateComponent(JurisdictionLevel::State, '7.2500')]);

    expect($rate->hasComponents())->toBeTrue();
});

it('treats no components as "not decomposable", not as a single authority', function () {
    $rate = new TaxRate('7.25');

    expect($rate->hasComponents())->toBeFalse()
        ->and($rate->components)->toBe([]);
});

// ---- What the dataset source emits ---------------------------------------

it('keeps every stacked authority as a component', function () {
    // 66101-6200 is a Kansas City address: state 6.5% + county 1% + city 1.625%.
    $rate = new UsTaxDatasetRateSource($this->dataset)
        ->rateFor(breakdownPlace('US-KS', zip9('US-KS', '66101-6200')), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('9.125')
        ->and(array_map(fn (RateComponent $c): array => [
            $c->level->value, (string) $c->percentage, $c->code,
        ], $rate?->components ?? []))->toBe([
            // The state carries a code too: an authority with none cannot be
            // merged with itself across the lines of a document.
            ['state', '6.5', 'US-KS'],
            ['county', '1', '209'],
            ['city', '1.625', '36000'],
        ]);
});

it('splits a combined-basis rate into the state share and the aggregate local share', function () {
    // California publishes one all-in figure per place; the state share is known
    // exactly, so the remainder is the aggregate of every district taxing there —
    // levelled `local`, never attributed to the named city.
    $locality = new LocalityCode(new SubdivisionCode('US-CA'), 'ca-place', '06:ALAMEDA');

    $rate = new UsTaxDatasetRateSource($this->dataset)
        ->rateFor(breakdownPlace('US-CA', $locality), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('10.75')
        ->and(array_map(fn (RateComponent $c): array => [
            $c->level->value, (string) $c->percentage, $c->name,
        ], $rate?->components ?? []))->toBe([
            ['state', '7.25', null],
            ['local', '3.5', 'ALAMEDA'],
        ]);
});

it('carries no components on a bare state rate', function () {
    // The state share is not a breakdown of an all-in rate — it is the absence of
    // one. Emitting a single "state" component would claim the locals are zero.
    $rate = new UsTaxDatasetRateSource($this->dataset)
        ->rateFor(breakdownPlace('US-CA'), TaxClass::GeneralGoods);

    expect($rate?->hasComponents())->toBeFalse();
});

it('carries no components on a reduced-rate category rule', function () {
    // Missouri's 1.225% grocery rate is a product rule, not a stack of authorities.
    $rate = new UsTaxDatasetRateSource($this->dataset)
        ->rateFor(breakdownPlace('US-MO'), TaxClass::Groceries);

    expect((string) $rate?->percentage)->toBe('1.225')
        ->and($rate?->hasComponents())->toBeFalse();
});

it('refuses a combined-basis rooftop with no local record rather than reporting the bare state share', function () {
    // A combined record IS the all-in rate, so "no record applies here" leaves no
    // all-in rate to report — unlike a component-basis state, where the state
    // share genuinely is the whole rate. Falling back to Derived says so.
    $directory = fixtureWithEmptyBoundarySet('US-CA');

    $dataset = new UsTaxDataset($this->app->make(Factory::class), $this->app->make(Cache::class), $directory);

    $rate = new UsTaxDatasetRateSource($dataset)
        ->rateFor(breakdownPlace('US-CA', zip9('US-CA', '94501-1234')), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('7.25')
        ->and($rate?->confidence->value)->toBe('derived')
        ->and($rate?->hasComponents())->toBeFalse();
});

// ---- Allocation: the parts sum to the whole ------------------------------

it('splits the assessed tax across the authorities that levy it', function () {
    $assessment = datasetCalculator($this->dataset)
        ->assess(breakdownQuery(breakdownPlace('US-KS', zip9('US-KS', '66101-6200'))));

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $assessment->tax->getAmount())->toBe('9.13'); // 9.125% of 100.00

    $this->assertBreakdownReconciles($assessment, ['state', 'county', 'city']);

    expect(array_map(
        fn ($line): string => (string) $line->tax->getAmount(),
        $assessment->breakdown?->lines ?? [],
    ))->toBe(['6.50', '1.00', '1.63']);
});

it('allocates the real total instead of rounding each share on its own', function () {
    // 9.125% of $1.00 is $0.09. Rounding each authority independently gives
    // 0.07 + 0.01 + 0.02 = 0.10 — a cent that was never charged, and a filing
    // that does not balance. Allocation distributes the 9 cents actually taken.
    $assessment = datasetCalculator($this->dataset)
        ->assess(breakdownQuery(breakdownPlace('US-KS', zip9('US-KS', '66101-6200')), '1.00'));

    expect((string) $assessment->tax->getAmount())->toBe('0.09');

    $this->assertBreakdownReconciles($assessment);

    // The two cents of remainder go to the largest fractional shares (county,
    // then city) — not to whichever authority the source happened to list first.
    expect(array_map(
        fn ($line): string => (string) $line->tax->getAmount(),
        $assessment->breakdown?->lines ?? [],
    ))->toBe(['0.06', '0.01', '0.02']);
});

it('reconciles on tax-inclusive pricing too', function () {
    // The tax is extracted from the gross rather than added to the net, so the
    // total it allocates is a different number — the invariant must still hold.
    $assessment = datasetCalculator($this->dataset)->assess(breakdownQuery(
        breakdownPlace('US-KS', zip9('US-KS', '66101-6200')),
        '109.13',
        Pricing::Inclusive,
    ));

    expect((string) $assessment->gross->getAmount())->toBe('109.13');

    $this->assertBreakdownReconciles($assessment, ['state', 'county', 'city']);
});

it('reports every taxable base as the supply net', function () {
    $assessment = datasetCalculator($this->dataset)
        ->assess(breakdownQuery(breakdownPlace('US-KS', zip9('US-KS', '66101-6200'))));

    foreach ($assessment->breakdown?->lines ?? [] as $line) {
        expect($line->taxableAmount->isEqualTo($assessment->net))->toBeTrue();
    }
});

// ---- When there is deliberately no breakdown ------------------------------

it('leaves the breakdown null when the source cannot decompose the rate', function () {
    // The static source ships flat percentages with no authority split. Null says
    // "unknown", which a caller must not read as "the state takes all of it".
    $calculator = new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(new StaticProductTaxability),
        new StaticTaxRateSource(['US-CA' => '7.25']),
    );

    $assessment = $calculator->assess(breakdownQuery(breakdownPlace('US-CA')));

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and($assessment->breakdown)->toBeNull();
});

it('leaves the breakdown null on a zero-rated supply', function () {
    $calculator = $this->taxCalculator(null, ['DK:digital_service' => new RateBand('0', RateKind::Zero)]);

    $assessment = $calculator->assess(new TaxQuery(
        amount: Money::of('100.00', 'DKK'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
        category: TaxClass::DigitalService,
    ));

    expect($assessment->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($assessment->breakdown)->toBeNull();
});

it('leaves the breakdown null when a buyer exemption overrides the tax', function () {
    // The exemption rewrites a would-be taxed supply to zero tax; there is then
    // nothing to split, and a stale breakdown would say otherwise.
    $place = breakdownPlace('US-KS', zip9('US-KS', '66101-6200'));

    $query = new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: $place,
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-KS')),
        ]),
        exemption: $this->taxExemption(subdivisions: ['US-KS']),
    );

    $assessment = datasetCalculator($this->dataset)->assess($query);

    $this->assertExempt($assessment);
    expect($assessment->breakdown)->toBeNull();
});

it('leaves the breakdown null where no tax is charged at all', function () {
    // Seller registered in Kansas, selling into California: no nexus, no tax, and
    // so nothing to attribute to anyone.
    $query = new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: breakdownPlace('US-CA'),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-KS')),
        ]),
    );

    $assessment = datasetCalculator($this->dataset)->assess($query);

    expect($assessment->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($assessment->breakdown)->toBeNull();
});

// ---- The breakdown value object -------------------------------------------

it('reads back as an empty breakdown when built with no lines', function () {
    // A zero-argument instance must be valid, so a consumer can stub the type.
    $breakdown = new TaxBreakdown;

    expect($breakdown->isEmpty())->toBeTrue()
        ->and($breakdown->total())->toBeNull()
        ->and($breakdown->atLevel(JurisdictionLevel::State))->toBe([]);
});

it('selects the lines levied at one layer of government', function () {
    $assessment = datasetCalculator($this->dataset)
        ->assess(breakdownQuery(breakdownPlace('US-KS', zip9('US-KS', '66101-6200'))));

    $state = $assessment->breakdown?->atLevel(JurisdictionLevel::State) ?? [];

    expect($state)->toHaveCount(1)
        ->and((string) $state[0]->tax->getAmount())->toBe('6.50')
        ->and($assessment->breakdown?->atLevel(JurisdictionLevel::SpecialDistrict))->toBe([]);
});

it('labels a line by name, then code, then level', function () {
    $named = new RateComponent(JurisdictionLevel::Local, '3.5', '06:ALAMEDA', 'ALAMEDA');
    $coded = new RateComponent(JurisdictionLevel::County, '1', '209');
    $bare = new RateComponent(JurisdictionLevel::State, '6.5');

    expect($named->label())->toBe('ALAMEDA')
        ->and($coded->label())->toBe('209')
        ->and($bare->label())->toBe('state');
});

/**
 * A copy of the dataset fixture whose boundary index positively answers "no local
 * authority applies" for the given state — the shape a state with no local sales
 * tax publishes.
 */
function fixtureWithEmptyBoundarySet(string $state): string
{
    $source = dirname(__DIR__).'/Fixtures/us-tax-dataset';
    $directory = sys_get_temp_dir().'/cbox-tax-breakdown-'.bin2hex(random_bytes(4));

    mkdir($directory.'/boundaries', 0o755, true);
    mkdir($directory.'/by-section', 0o755, true);

    foreach (['rates', 'baseline', 'taxability', 'nexus', 'sourcing'] as $section) {
        copy($source.'/by-section/'.$section.'.json', $directory.'/by-section/'.$section.'.json');
    }

    file_put_contents($directory.'/boundaries/'.$state.'.json', json_encode([
        'sets' => [[]],
        'zip' => (object) [],
        'ranges' => [['00000', '99999', '0000', '9999', 0]],
    ]));

    return $directory;
}

// ---- A reduced category is a reduced STATE share, not an all-in rate ------

it('stacks a reduced category on the locality food rate, not the general one', function () {
    // Missouri's 1.225% and Tennessee's 4% grocery rates are STATE shares — both
    // states' own guidance says local sales taxes still apply to food. Returning
    // the reduced figure as the whole rate under-charged by most of the true one.
    //
    // And the local share is not the general local rate either: the dataset
    // carries `foodDrugRate` per locality precisely because a city may levy 2.75%
    // generally and 2.25% on food.
    $directory = datasetWithFoodRate();

    $dataset = new UsTaxDataset($this->app->make(Factory::class), $this->app->make(Cache::class), $directory);
    $source = new UsTaxDatasetRateSource($dataset);

    $place = breakdownPlace('US-KS', zip9('US-KS', '66101-6200'));

    $general = $source->rateFor($place, TaxClass::GeneralGoods);
    $grocery = $source->rateFor($place, TaxClass::Groceries);

    expect((string) $general?->percentage)->toBe('9.75')   // 7% state + 2.75% local general
        ->and((string) $grocery?->percentage)->toBe('6.25') // 4% state + 2.25% local FOOD
        ->and($grocery?->kind)->toBe(RateKind::Reduced)
        ->and($grocery?->confidence)->toBe(Confidence::Authoritative);
});

it('decomposes a reduced rooftop rate into its authorities too', function () {
    $dataset = new UsTaxDataset($this->app->make(Factory::class), $this->app->make(Cache::class), datasetWithFoodRate());

    $grocery = new UsTaxDatasetRateSource($dataset)
        ->rateFor(breakdownPlace('US-KS', zip9('US-KS', '66101-6200')), TaxClass::Groceries);

    expect(array_map(fn (RateComponent $c): array => [
        $c->level->value, (string) $c->percentage,
    ], $grocery?->components ?? []))->toBe([
        ['state', '4'],
        ['county', '2.25'],
    ]);
});

/**
 * A dataset where one state reduces groceries and its single locality levies a
 * DIFFERENT rate on food than on everything else — the shape MO and TN publish.
 */
function datasetWithFoodRate(): string
{
    $directory = sys_get_temp_dir().'/cbox-tax-food-'.bin2hex(random_bytes(4));
    mkdir($directory.'/boundaries', 0o755, true);
    mkdir($directory.'/by-section', 0o755, true);

    file_put_contents($directory.'/by-section/baseline.json', json_encode(['states' => [
        'US-KS' => ['baseline' => [['stateRate' => 0.07, 'localsExist' => true, 'noSalesTax' => false, 'effectiveFrom' => null, 'effectiveTo' => null]]],
    ]]));

    file_put_contents($directory.'/by-section/taxability.json', json_encode(['states' => [
        'US-KS' => [
            ['category' => 'goods_general', 'taxable' => true, 'treatment' => 'taxable', 'conditions' => null, 'effectiveFrom' => null, 'effectiveTo' => null],
            ['category' => 'grocery', 'taxable' => true, 'treatment' => 'reduced_rate', 'conditions' => ['rate' => 0.04], 'effectiveFrom' => null, 'effectiveTo' => null],
        ],
    ]]));

    file_put_contents($directory.'/by-section/rates.json', json_encode(['states' => [
        'US-KS' => [
            'rateBasis' => 'component',
            'stateRate' => 0.07,
            'local' => ['209' => [[
                'level' => 'county',
                'jurisdictionName' => null,
                'generalRate' => 0.0275,
                'foodDrugRate' => 0.0225,
                'effectiveFrom' => null,
                'effectiveTo' => null,
            ]]],
        ],
    ]]));

    foreach (['nexus', 'sourcing'] as $section) {
        file_put_contents($directory.'/by-section/'.$section.'.json', json_encode(['states' => []]));
    }

    file_put_contents($directory.'/boundaries/US-KS.json', json_encode([
        'sets' => [['209']],
        'zip' => (object) [],
        'ranges' => [['66000', '67999', '0000', '9999', 0]],
    ]));

    return $directory;
}
