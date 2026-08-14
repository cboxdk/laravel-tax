<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\Taxability\UsTaxDatasetTaxability;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

// Two arithmetic defects that every other test in this suite walked straight past,
// because no shipped fixture puts the conditions together: the states with a
// reduced grocery rate (MO, MS, TN, VA) are not the states with local records in
// the fixture (CA, TX, NC, KS), and every rate window in it is unbounded.
//
// So these build the dataset that does. A defect nothing can reach is a defect
// nothing catches.

/**
 * A state with BOTH a reduced grocery rate and a local authority, and a state rate
 * that changed on 2026-01-01.
 */
function stackingDataset(): UsTaxDataset
{
    $dir = sys_get_temp_dir().'/tax-stack-'.bin2hex(random_bytes(5));
    mkdir($dir.'/by-section', 0o755, true);

    $window = static fn (float $rate, ?string $from, ?string $to): array => [
        'level' => 'state',
        'jurisdictionName' => null,
        'generalRate' => $rate,
        'generalRateIntrastate' => $rate,
        'foodDrugRate' => 0,
        'effectiveFrom' => $from,
        'effectiveTo' => $to,
    ];

    file_put_contents($dir.'/by-section/rates.json', json_encode(['states' => [
        'US-KS' => [
            'stateFips' => '20',
            'rateBasis' => 'component',
            'sourceMode' => 'test',
            // The state share moved from 5.5% to 6.5% on 2026-01-01.
            'stateRate' => ['20' => [$window(0.055, null, '2025-12-31'), $window(0.065, '2026-01-01', null)]],
            'state' => [],
            'local' => ['36000' => [[
                'level' => 'city',
                'jurisdictionName' => 'Test City',
                'generalRate' => 0.01,
                'generalRateIntrastate' => 0.01,
                'foodDrugRate' => 0.01,
                'effectiveFrom' => null,
                'effectiveTo' => null,
            ]]],
        ],
    ]], JSON_THROW_ON_ERROR));

    // Groceries taxed at a reduced STATE share of 2%.
    file_put_contents($dir.'/by-section/taxability.json', json_encode(['states' => [
        'US-KS' => [[
            'category' => 'grocery',
            'taxable' => true,
            'treatment' => 'reduced_rate',
            'conditions' => ['rate' => 0.02],
            'effectiveFrom' => null,
            'effectiveTo' => null,
        ]],
    ]], JSON_THROW_ON_ERROR));

    // The state share lives in the BASELINE section, not the rate section — and a
    // fixture without it is how the missing-state-share refusal below was found.
    file_put_contents($dir.'/by-section/baseline.json', json_encode(['states' => [
        'US-KS' => ['coverage' => 'locals', 'baseline' => [
            ['stateRate' => 0.055, 'noSalesTax' => false, 'localsExist' => true, 'effectiveFrom' => null, 'effectiveTo' => '2025-12-31'],
            ['stateRate' => 0.065, 'noSalesTax' => false, 'localsExist' => true, 'effectiveFrom' => '2026-01-01', 'effectiveTo' => null],
        ]],
    ]], JSON_THROW_ON_ERROR));

    return new UsTaxDataset(app(Factory::class), app(Cache::class), $dir);
}

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $dataset = stackingDataset();

    $this->calculator = new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(
            new UsTaxDatasetTaxability($dataset, new StaticProductTaxability),
            $this->geo,
        ),
        new UsTaxDatasetRateSource($dataset),
    );
});

function stackedSupply(TaxClass $class, string $suppliedAt): TaxQuery
{
    return new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('US'), new SubdivisionCode('US-KS'))
            ->withLocality(new LocalityCode(new SubdivisionCode('US-KS'), 'sst-fips', '36000')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-KS')),
        ]),
        category: $class,
        suppliedAt: new DateTimeImmutable($suppliedAt),
    );
}

it('keeps the local share when a state reduces the rate for a category', function () {
    // A state's reduced grocery rate is its OWN share; local food taxes still apply
    // on top. The rate source stacks them correctly — 2% state plus 1% city — and
    // then the regime substituted the bare state figure over the top, throwing the
    // local half away. The result wore the state's own published percentage, which
    // is what made it look right.
    $assessment = $this->calculator->assess(stackedSupply(TaxClass::Groceries, '2026-06-15'));

    expect((string) $assessment->rate?->percentage)->toBe('3')     // 2% state + 1% city
        ->and((string) $assessment->tax->getAmount())->toBe('3.00')
        // ...and the split survives, so the city can still be paid its share.
        ->and($assessment->breakdown?->lines)->toHaveCount(2);
});

it('stacks the state share that was in force on the SUPPLY date', function () {
    // The date reached the local records but not the state share, so a 2025 supply
    // was stacked with 2026's state rate: a percentage that was never in force
    // anywhere, on either date.
    $before = $this->calculator->assess(stackedSupply(TaxClass::GeneralGoods, '2025-06-15'));
    $after = $this->calculator->assess(stackedSupply(TaxClass::GeneralGoods, '2026-06-15'));

    expect((string) $before->rate?->percentage)->toBe('6.5')   // 5.5% state + 1% city
        ->and((string) $after->rate?->percentage)->toBe('7.5'); // 6.5% state + 1% city
});

it('keeps the parts summing to the whole on a stacked reduced rate', function () {
    $assessment = $this->calculator->assess(stackedSupply(TaxClass::Groceries, '2026-06-15'));

    $sum = null;

    foreach ($assessment->breakdown?->lines ?? [] as $line) {
        $sum = $sum === null ? $line->tax : $sum->plus($line->tax);
    }

    expect($sum?->isEqualTo($assessment->tax))->toBeTrue();
});

it('refuses a rooftop rate rather than returning the locals alone', function () {
    // Found by a fixture that forgot the baseline section, which is exactly the
    // shape of a real failure: a state missing from the baseline overlay, or a
    // section that would not load. On a component-basis state the local records
    // are only the ADDEND, so skipping the state share quietly returned 1% where
    // 7.5% was due — four fifths of the tax gone, on an answer stamped
    // authoritative. Refusing sends the caller to the state rate, which is
    // unavailable for the same reason, so the engine denies.
    $dir = sys_get_temp_dir().'/tax-nobase-'.bin2hex(random_bytes(5));
    mkdir($dir.'/by-section', 0o755, true);

    file_put_contents($dir.'/by-section/rates.json', json_encode(['states' => [
        'US-KS' => [
            'stateFips' => '20',
            'rateBasis' => 'component',
            'sourceMode' => 'test',
            'stateRate' => [],
            'state' => [],
            'local' => ['36000' => [[
                'level' => 'city',
                'jurisdictionName' => 'Test City',
                'generalRate' => 0.01,
                'generalRateIntrastate' => 0.01,
                'foodDrugRate' => 0.01,
                'effectiveFrom' => null,
                'effectiveTo' => null,
            ]]],
        ],
    ]], JSON_THROW_ON_ERROR));

    $source = new UsTaxDatasetRateSource(
        new UsTaxDataset($this->app->make(Factory::class), $this->app->make(Cache::class), $dir),
    );

    $place = $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-KS'))
        ->withLocality(new LocalityCode(new SubdivisionCode('US-KS'), 'sst-fips', '36000'));

    expect($source->rateFor($place, TaxClass::GeneralGoods))->toBeNull();
});
