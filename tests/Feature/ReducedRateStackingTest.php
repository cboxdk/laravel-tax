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
use Cbox\Tax\Register\Reader\RateResolver;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterBoundaries;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\Register\Sources\RegisterTaxability;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

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
function stackingDataset(): RegisterDataset
{
    $root = sys_get_temp_dir().'/tax-stack-'.bin2hex(random_bytes(5));

    // The state share moved from 5.5% to 6.5% on 2026-01-01, and the city levies
    // 1.625% throughout. Stacking today's state share onto a historical local
    // produces a percentage that was never in force anywhere.
    FakeRegister::at($root)
        ->rate('us:KS', '5.5', from: '1990-01-01', until: '2025-12-31')
        ->rate('us:KS', '6.5', from: '2026-01-01')
        ->rate('us:KS', '2', 'reduced', 'goods.food.basic', from: '1990-01-01')
        ->rate('us:KS:CITY-36000', '1', 'local_component', from: '1990-01-01')
        ->rate('us:KS:CITY-36000', '1', 'local_component', 'goods.food.basic', from: '1990-01-01')
        ->boundary('KS', '66101', ['state:20', 'city:36000'])
        ->install();

    $layout = new StoreLayout($root);

    return new RegisterDataset($layout, new StorePointer($layout));
}

beforeEach(function (): void {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $dataset = stackingDataset();

    $this->calculator = new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(
            new RegisterTaxability($dataset),
            $this->geo,
        ),
        // The SAME register the taxability reads, or the two halves of the answer
        // come from different worlds and the arithmetic is nobody's.
        new RegisterRateSource(
            $dataset,
            new RateResolver,
            new RegisterBoundaries(new StoreLayout($dataset->storeRoot()), (string) $dataset->version(), $dataset),
        ),
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

it('keeps the local share when a state reduces the rate for a category', function (): void {
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

it('stacks the state share that was in force on the SUPPLY date', function (): void {
    // The date reached the local records but not the state share, so a 2025 supply
    // was stacked with 2026's state rate: a percentage that was never in force
    // anywhere, on either date.
    $before = $this->calculator->assess(stackedSupply(TaxClass::GeneralGoods, '2025-06-15'));
    $after = $this->calculator->assess(stackedSupply(TaxClass::GeneralGoods, '2026-06-15'));

    expect((string) $before->rate?->percentage)->toBe('6.5')   // 5.5% state + 1% city
        ->and((string) $after->rate?->percentage)->toBe('7.5'); // 6.5% state + 1% city
});

it('keeps the parts summing to the whole on a stacked reduced rate', function (): void {
    $assessment = $this->calculator->assess(stackedSupply(TaxClass::Groceries, '2026-06-15'));

    $sum = null;

    foreach ($assessment->breakdown?->lines ?? [] as $line) {
        $sum = $sum === null ? $line->tax : $sum->plus($line->tax);
    }

    expect($sum?->isEqualTo($assessment->tax))->toBeTrue();
});

it('refuses a rooftop rate rather than returning the locals alone', function (): void {
    // On a component-basis state the local records are only the ADDEND, so skipping
    // the state share quietly returns 1% where 7.5% is due — four fifths of the tax
    // gone, on an answer stamped authoritative. With no state share to stack onto,
    // the engine has to deny.
    $root = sys_get_temp_dir().'/tax-nobase-'.bin2hex(random_bytes(5));

    FakeRegister::at($root)
        ->rate('us:KS:CITY-36000', '1', 'local_component', from: '1990-01-01')
        ->boundary('KS', '66101', ['state:20', 'city:36000'])
        ->install();

    $layout = new StoreLayout($root);
    $dataset = new RegisterDataset($layout, new StorePointer($layout));

    $source = new RegisterRateSource(
        $dataset,
        new RateResolver,
        new RegisterBoundaries($layout, (string) $dataset->version(), $dataset),
    );

    $place = test()->geo->find(new CountryCode('US'), new SubdivisionCode('US-KS'))
        ->withLocality(new LocalityCode(new SubdivisionCode('US-KS'), 'sst-fips', '36000'));

    expect($source->rateFor($place, TaxClass::GeneralGoods))->toBeNull();
});
