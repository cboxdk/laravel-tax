<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Exceptions\ImplausibleTaxRate;
use Cbox\Tax\Exceptions\UnresolvedProductTaxability;
use Cbox\Tax\RateSource\CachingTaxRateSource;
use Cbox\Tax\RateSource\ChainTaxRateSource;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\Taxability\UsTaxDatasetTaxability;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
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
    $this->taxability = new UsTaxDatasetTaxability($this->dataset, new StaticProductTaxability);
});

/**
 * A dataset carrying the Massachusetts clothing rule with one field removed, so
 * the engine's handling of incomplete data can be tested against data that really
 * is incomplete rather than against a mock that says it is.
 */
function datasetWithout(string $field): UsTaxDataset
{
    $dir = sys_get_temp_dir().'/tax-partial-'.bin2hex(random_bytes(5));
    mkdir($dir.'/by-section', 0o755, true);

    $conditions = ['exemptBelowCents' => 17500, 'thresholdRule' => 'excess_taxable'];
    unset($conditions[$field]);

    file_put_contents($dir.'/by-section/taxability.json', json_encode(['states' => [
        'US-MA' => [[
            'category' => 'clothing',
            'taxable' => true,
            'treatment' => 'conditional',
            'conditions' => $conditions,
            'effectiveFrom' => null,
            'effectiveTo' => null,
        ]],
    ]], JSON_THROW_ON_ERROR));

    return new UsTaxDataset(app(Factory::class), app(Cache::class), $dir);
}

function denyPlace(string $state): Jurisdiction
{
    return test()->geo->find(new CountryCode('US'), new SubdivisionCode($state));
}

// ---- Undetermined categories refuse rather than defaulting to taxable -----

it('refuses a US category the dataset leaves undetermined', function () {
    // The dataset omits these pairs DELIBERATELY — its sources disagree. Inheriting
    // that as "taxable" turns a documented gap into a silent over-collection.
    $undetermined = [
        ['US-CA', TaxClass::SoftwarePrewritten],
        ['US-CA', TaxClass::DietarySupplements],
        ['US-KS', TaxClass::Candy],
        ['US-AL', TaxClass::Groceries],
        ['US-RI', TaxClass::RepairService],
    ];

    foreach ($undetermined as [$state, $category]) {
        expect(fn () => $this->taxability->determine(denyPlace($state), $category, anyAmount()))
            ->toThrow(UnresolvedProductTaxability::class);
    }
});

it('still taxes general tangible goods by default, which is the one honest default', function () {
    // Every US sales-tax state taxes general merchandise, so this rule states the
    // law rather than guessing. Alaska is the only state the dataset leaves
    // undetermined for it.
    expect($this->taxability->determine(denyPlace('US-CA'), TaxClass::GeneralGoods, anyAmount())->isExemptFor(anyAmount()))->toBeFalse()
        ->and(new StaticProductTaxability()->determine(denyPlace('US-TX'), TaxClass::GeneralGoods, anyAmount())->isExemptFor(anyAmount()))->toBeFalse();
});

it('keeps the goods default outside the US', function () {
    expect($this->taxability->determine($this->geo->find(new CountryCode('DE')), TaxClass::GeneralGoods, anyAmount())->isExemptFor(anyAmount()))->toBeFalse()
        ->and($this->taxability->determine($this->geo->find(new CountryCode('DE')), TaxClass::Book, anyAmount())->isExemptFor(anyAmount()))->toBeFalse();
});

it('honours an explicit override instead of refusing', function () {
    $configured = new StaticProductTaxability(['US-CA:software_prewritten' => false]);

    expect($configured->determine(denyPlace('US-CA'), TaxClass::SoftwarePrewritten, anyAmount())->isExemptFor(anyAmount()))->toBeTrue();
});

// ---- Conditional rules refuse rather than charging the full rate ----------

it('refuses a threshold rule that does not say how the threshold applies', function () {
    // The seam now receives the amount, so a threshold is decidable — but only if
    // the data says WHICH threshold it is. Massachusetts taxes the amount over
    // $175; New York taxes the whole item once it reaches $110. A rule carrying
    // the figure without the mechanic is refused rather than guessed, because
    // guessing wrong under-collects on every garment over the line in New York.
    $incomplete = new UsTaxDatasetTaxability(
        datasetWithout('thresholdRule'),
        new StaticProductTaxability,
    );

    expect(fn () => $incomplete->determine(denyPlace('US-MA'), TaxClass::Clothing, anyAmount('200.00')))
        ->toThrow(UnresolvedProductTaxability::class, 'conditional');
});

it('is unaffected where clothing carries a plain determination', function () {
    // California taxes clothing outright — no condition, no refusal.
    expect($this->taxability->determine(denyPlace('US-CA'), TaxClass::Clothing, anyAmount())->isExemptFor(anyAmount()))->toBeFalse();
});

// ---- A rate outside 0-100% is corrupt data, not a rate --------------------

it('refuses a negative rate, which would credit tax back on every invoice', function () {
    expect(fn () => new TaxRate('-25'))->toThrow(ImplausibleTaxRate::class);
});

it('refuses an absurd rate, the signature of a fraction/percent unit mismatch', function () {
    // A schemaVersion change publishing 7.25 where 0.0725 was expected, multiplied
    // by 100, lands here as 725%.
    expect(fn () => new TaxRate('725'))->toThrow(ImplausibleTaxRate::class)
        ->and(fn () => new TaxRate('100.01'))->toThrow(ImplausibleTaxRate::class);
});

it('accepts the whole legitimate range', function () {
    expect((string) new TaxRate('0')->percentage)->toBe('0')
        ->and((string) new TaxRate('100')->percentage)->toBe('100')
        ->and((string) new TaxRate('27')->percentage)->toBe('27'); // Hungary, the EU maximum
});

// ---- A zero state share under real local taxes is not an answer -----------

it('refuses Alaska rather than reporting an affirmative 0%', function () {
    // Alaska levies no STATE sales tax while its boroughs and cities levy their
    // own (Juneau 5%, Wrangell 7%). Unlike DE/MT/NH/OR — which carry
    // noSalesTax and already resolve null — Alaska's baseline is stateRate 0 with
    // localsExist true, which used to produce a real 0% Standard rate: a confident
    // "no tax due" on a supply that is taxed.
    $rate = new UsTaxDatasetRateSource($this->dataset)->rateFor(denyPlace('US-AK'), TaxClass::GeneralGoods);

    expect($rate)->toBeNull();
});

it('still returns the state share where it is a genuine floor', function () {
    // Every other state's share under-states the total but is a real number a
    // caller can reason about at Derived confidence.
    $rate = new UsTaxDatasetRateSource($this->dataset)->rateFor(denyPlace('US-TX'), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('6.25')
        ->and($rate?->confidence->value)->toBe('derived');
});

// ---- A commodity code must survive the wrappers ---------------------------

it('forwards a commodity code through a chain to a source that can use one', function () {
    $aware = new class implements CommodityRateSource
    {
        public function rateFor($jurisdiction, $category, ?DateTimeImmutable $at = null): ?TaxRate
        {
            return new TaxRate('23'); // the standard rate: no code, no refinement
        }

        public function rateForCommodity($jurisdiction, $category, ?string $commodityCode, ?DateTimeImmutable $at = null): ?TaxRate
        {
            return $commodityCode === '0201' ? new TaxRate('5') : $this->rateFor($jurisdiction, $category, $at);
        }
    };

    $chain = new ChainTaxRateSource([$aware, new StaticTaxRateSource]);
    $place = $this->geo->find(new CountryCode('PL'));

    expect((string) $chain->rateForCommodity($place, TaxClass::Groceries, '0201')?->percentage)->toBe('5')
        ->and((string) $chain->rateFor($place, TaxClass::Groceries)?->percentage)->toBe('23');
});

it('resolves the commodity code through the calculator, not just the source', function () {
    // The regression this guards: the provider composes a chain whenever a live
    // source is enabled, and ResolvesRates decides by testing the OUTERMOST source.
    // A chain that hid the capability made every commodity-aware source beneath it
    // unreachable — silently, with the source's own tests still passing.
    $aware = new class implements CommodityRateSource
    {
        public function rateFor($jurisdiction, $category, ?DateTimeImmutable $at = null): ?TaxRate
        {
            return new TaxRate('23');
        }

        public function rateForCommodity($jurisdiction, $category, ?string $commodityCode, ?DateTimeImmutable $at = null): ?TaxRate
        {
            return $commodityCode === '0201' ? new TaxRate('5') : $this->rateFor($jurisdiction, $category, $at);
        }
    };

    $calculator = new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(new StaticProductTaxability, $this->geo),
        new ChainTaxRateSource([$aware]),
    );

    $assessment = $calculator->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('PL')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('PL')),
        category: TaxClass::Groceries,
        commodityCode: '0201',
    ));

    expect((string) $assessment->rate?->percentage)->toBe('5')
        ->and((string) $assessment->tax->getAmount())->toBe('5.00');
});

it('composes a chain that advertises the capability', function () {
    // Guards the contract itself: ResolvesRates branches on instanceof.
    expect(new ChainTaxRateSource([]))->toBeInstanceOf(CommodityRateSource::class)
        ->and(new CachingTaxRateSource(new StaticTaxRateSource, $this->app->make(Cache::class)))
        ->toBeInstanceOf(CommodityRateSource::class);
});

// ---- The rate cache must not serve one rooftop's rate for another ---------

it('keys the rate cache by rooftop locality', function () {
    $inner = new UsTaxDatasetRateSource($this->dataset);
    $caching = new CachingTaxRateSource($inner, $this->app->make(Cache::class));

    $kansasCity = denyPlace('US-KS')->withLocality(
        new LocalityCode(new SubdivisionCode('US-KS'), UsTaxDatasetRateSource::ZIP9_SCHEME, '66101-6200'),
    );

    // Warm the cache at the rooftop, then ask for the bare state. Without the
    // locality in the key the second call would be served Kansas City's 9.125%.
    expect((string) $caching->rateFor($kansasCity, TaxClass::GeneralGoods)?->percentage)->toBe('9.125')
        ->and((string) $caching->rateFor(denyPlace('US-KS'), TaxClass::GeneralGoods)?->percentage)->toBe('6.5');
});

it('keys the rate cache by commodity code', function () {
    // The inner source MUST answer differently for the two lookups, or the test
    // passes whether or not the code is in the key — which is exactly the bug it
    // is supposed to catch.
    $inner = new class implements CommodityRateSource
    {
        public function rateFor($jurisdiction, $category, ?DateTimeImmutable $at = null): ?TaxRate
        {
            return new TaxRate('23');
        }

        public function rateForCommodity($jurisdiction, $category, ?string $commodityCode, ?DateTimeImmutable $at = null): ?TaxRate
        {
            return $commodityCode === '0201' ? new TaxRate('5') : new TaxRate('23');
        }
    };

    $caching = new CachingTaxRateSource($inner, $this->app->make(Cache::class));
    $place = $this->geo->find(new CountryCode('PL'));

    // Warm the cache with the code-less lookup, then ask with a code. Without the
    // code in the key the second call would be served the cached 23%.
    expect((string) $caching->rateFor($place, TaxClass::Groceries)?->percentage)->toBe('23')
        ->and((string) $caching->rateForCommodity($place, TaxClass::Groceries, '0201')?->percentage)->toBe('5')
        // ...and back the other way, so neither poisons the other.
        ->and((string) $caching->rateFor($place, TaxClass::Groceries)?->percentage)->toBe('23');
});

it('caches today but never serves a historical rate from the current-rate cache', function () {
    // Threading the supply date made every calculator call carry one. A cache that
    // bypassed on "a date was supplied" rather than "the date is not today" would
    // silently stop caching altogether and put the live feed back on the hot path.
    $calls = 0;
    $inner = new class($calls) implements TaxRateSource
    {
        public function __construct(private int &$calls) {}

        public function rateFor($jurisdiction, $category, ?DateTimeImmutable $at = null): ?TaxRate
        {
            $this->calls++;

            return new TaxRate($at !== null && $at->format('Y') === '2020' ? '16' : '19');
        }
    };

    $caching = new CachingTaxRateSource($inner, $this->app->make(Cache::class));
    $place = $this->geo->find(new CountryCode('DE'));
    $today = new DateTimeImmutable;

    expect((string) $caching->rateFor($place, TaxClass::GeneralGoods, $today)?->percentage)->toBe('19')
        ->and((string) $caching->rateFor($place, TaxClass::GeneralGoods, $today)?->percentage)->toBe('19')
        ->and($calls)->toBe(1); // the second call was served from cache

    // A historical lookup bypasses the cache entirely — and does not poison it.
    expect((string) $caching->rateFor($place, TaxClass::GeneralGoods, new DateTimeImmutable('2020-08-15'))?->percentage)->toBe('16')
        ->and($calls)->toBe(2)
        ->and((string) $caching->rateFor($place, TaxClass::GeneralGoods, $today)?->percentage)->toBe('19')
        ->and($calls)->toBe(2); // still cached, still today's rate
});

// ---- The calculator refuses, it does not guess ----------------------------

it('refuses to assess rather than over-collect on an undetermined category', function () {
    $calculator = new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults($this->taxability, $this->geo),
        new UsTaxDatasetRateSource($this->dataset),
    );

    $query = new TaxQuery(
        amount: Money::of('1000.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: denyPlace('US-CA'),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-CA')),
        ]),
        category: TaxClass::SoftwarePrewritten,
    );

    expect(fn () => $calculator->assess($query))->toThrow(UnresolvedProductTaxability::class);
});
