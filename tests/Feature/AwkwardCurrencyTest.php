<?php

declare(strict_types=1);

use Brick\Money\AllocationMode;
use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\ValueObjects\RateComponent;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;

// Every other test in this suite uses a two-decimal currency. The package ships
// JP 10% (JPY, ZERO decimals) and BH 5%/10% (BHD, THREE), which is where the
// rounding in TaxRate and the Hamilton allocation is most likely to be a unit out.

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

function supplyIn(string $country, string $amount, string $currency, Pricing $pricing = Pricing::Exclusive): TaxQuery
{
    return new TaxQuery(
        amount: Money::of($amount, $currency),
        pricing: $pricing,
        place: test()->geo->find(new CountryCode($country)),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode($country)),
        category: TaxClass::DigitalService,
    );
}

// ---- Zero-decimal money (JPY) --------------------------------------------

it('assesses zero-decimal money without inventing a fraction', function () {
    // 1,000 JPY at 10% = 100 JPY exactly.
    $exact = $this->tax->assess(supplyIn('JP', '1000', 'JPY'));

    expect((string) $exact->tax->getAmount())->toBe('100')
        ->and((string) $exact->gross->getAmount())->toBe('1100');
});

it('rounds zero-decimal tax to a whole unit', function () {
    // 1,005 JPY at 10% = 100.5, which does not exist in yen. Half-up gives 101,
    // and the gross must agree with it rather than with the unrounded figure.
    $rounded = $this->tax->assess(supplyIn('JP', '1005', 'JPY'));

    expect((string) $rounded->tax->getAmount())->toBe('101')
        ->and((string) $rounded->gross->getAmount())->toBe('1106')
        ->and($rounded->gross->minus($rounded->net)->isEqualTo($rounded->tax))->toBeTrue();
});

it('extracts zero-decimal tax from a gross amount and still reconciles', function () {
    // The inclusive path divides rather than multiplies, which is where an
    // off-by-one hides: net + tax must equal the gross the caller quoted.
    foreach (['1100', '1000', '999', '1', '7'] as $gross) {
        $assessment = $this->tax->assess(supplyIn('JP', $gross, 'JPY', Pricing::Inclusive));

        expect((string) $assessment->gross->getAmount())->toBe($gross)
            ->and($assessment->net->plus($assessment->tax)->isEqualTo($assessment->gross))
            ->toBeTrue("net + tax must equal the quoted gross of {$gross} JPY");
    }
});

// ---- Three-decimal money (BHD) -------------------------------------------

it('assesses three-decimal money at full precision', function () {
    // 100.000 BHD at 10% = 10.000. The minor unit is a thousandth, so a rate that
    // rounds cleanly at two decimals may not here.
    $assessment = $this->tax->assess(supplyIn('BH', '100.000', 'BHD'));

    expect((string) $assessment->tax->getAmount())->toBe('10.000')
        ->and((string) $assessment->gross->getAmount())->toBe('110.000');
});

it('reconciles three-decimal money on the inclusive path', function () {
    foreach (['110.000', '100.001', '0.007', '55.555'] as $gross) {
        $assessment = $this->tax->assess(supplyIn('BH', $gross, 'BHD', Pricing::Inclusive));

        expect((string) $assessment->gross->getAmount())->toBe($gross)
            ->and($assessment->net->plus($assessment->tax)->isEqualTo($assessment->gross))
            ->toBeTrue("net + tax must equal the quoted gross of {$gross} BHD");
    }
});

// ---- The allocation must survive both ------------------------------------

it('allocates a stacked rate exactly in zero-decimal money', function () {
    // The Hamilton allocation distributes minor units. In yen a minor unit is a
    // whole yen, so a three-way split of a small tax is where it either holds or
    // visibly does not.
    $rate = new TaxRate('9.125', components: [
        new RateComponent(JurisdictionLevel::State, '6.5', 'X'),
        new RateComponent(JurisdictionLevel::County, '1', 'Y'),
        new RateComponent(JurisdictionLevel::City, '1.625', 'Z'),
    ]);

    foreach (['100', '11', '3', '1'] as $net) {
        $money = Money::of($net, 'JPY');
        $tax = $rate->taxOnNet($money);

        $shares = $tax->allocate(
            [$rate->components[0]->percentage, $rate->components[1]->percentage, $rate->components[2]->percentage],
            AllocationMode::FloorToLargestRemainder,
        );

        $summed = array_reduce(
            $shares,
            static fn (?Money $carry, Money $share): Money => $carry === null ? $share : $carry->plus($share),
        );

        expect($summed?->isEqualTo($tax))->toBeTrue("shares must sum to the {$net} JPY line's tax");
    }
});

it('produces a reconciling breakdown in zero-decimal money end to end', function () {
    // Through the calculator, not the allocator: AppliesTaxRate::breakdown() bails
    // out on a money context with no fixed scale, and that bail-out has never been
    // exercised against a currency whose scale is zero.
    $assessment = $this->tax->assess(supplyIn('JP', '1005', 'JPY'));

    // The shipped JP rate carries no components, so there is honestly no split —
    // and the engine must say so rather than invent one.
    expect($assessment->breakdown)->toBeNull()
        ->and($assessment->rate?->hasComponents())->toBeFalse();
});
