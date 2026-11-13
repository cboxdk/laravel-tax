<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Exceptions\ImplausibleTaxRate;
use Cbox\Tax\ValueObjects\BracketRow;
use Cbox\Tax\ValueObjects\BracketSchedule;
use Cbox\Tax\ValueObjects\RateComponent;
use Cbox\Tax\ValueObjects\TaxRate;

/*
 * A rate computed on a share of the price: the arithmetic, on the value object.
 */

it('leaves a rate without a share exactly as it was', function (): void {
    $rate = new TaxRate('8.25');

    expect($rate->taxableShare)->toBeNull()
        ->and((string) $rate->effectivePercentage())->toBe('8.25')
        ->and((string) $rate->taxOnNet(Money::of('100.00', 'USD'))->getAmount())->toBe('8.25')
        ->and((string) $rate->netFromGross(Money::of('108.25', 'USD'))->getAmount())->toBe('100.00')
        ->and((string) $rate->taxablePartOf(Money::of('100.00', 'USD'))->getAmount())->toBe('100.00');
});

it('computes the tax on the share, keeping the statutory percentage and components', function (): void {
    $rate = new TaxRate('8.25', components: [
        new RateComponent(JurisdictionLevel::State, '6.25', 'us:TX'),
        new RateComponent(JurisdictionLevel::City, '1', 'us:TX:CITY-AUSTIN'),
        new RateComponent(JurisdictionLevel::SpecialDistrict, '1', 'us:TX:DISTRICT-CAPMETRO'),
    ], taxableShare: '80');

    expect((string) $rate->percentage)->toBe('8.25')
        ->and((string) $rate->effectivePercentage())->toBe('6.6')
        ->and((string) $rate->taxOnNet(Money::of('100.00', 'USD'))->getAmount())->toBe('6.60')
        ->and((string) $rate->netFromGross(Money::of('106.60', 'USD'))->getAmount())->toBe('100.00')
        ->and((string) $rate->taxablePartOf(Money::of('100.00', 'USD'))->getAmount())->toBe('80.00');
});

it('rounds once, at the money, on the share', function (string $net, string $tax): void {
    // 5.6% on 65%: 3.64% of the price. $0.99 is 3.6036 cents, $12.37 is 45.0268.
    expect((string) new TaxRate('5.6', taxableShare: '65')->taxOnNet(Money::of($net, 'USD'))->getAmount())->toBe($tax);
})->with([['0.99', '0.04'], ['12.37', '0.45'], ['100000.00', '3640.00'], ['-12.37', '-0.45']]);

it('carries the share through every copy of the rate', function (): void {
    $rate = new TaxRate('6.25', taxableShare: '80');

    expect((string) $rate->qualifiedBy(RateLimit::TaxabilityAssumed)->taxableShare)->toBe('80')
        ->and((string) $rate->degraded('test')->taxableShare)->toBe('80')
        ->and((string) new TaxRate('6.25')->withTaxableShare('80')->taxableShare)->toBe('80')
        ->and($rate->withTaxableShare(null)->taxableShare)->toBeNull();
});

it('refuses a share that is not one', function (string $share): void {
    new TaxRate('6.25', taxableShare: $share);
})->with(['0', '-5', '100.01'])->throws(ImplausibleTaxRate::class, 'Taxable share');

it('refuses a share on a rate a table prices', function (): void {
    new TaxRate('6', schedule: new BracketSchedule('USD', [new BracketRow(BigDecimal::zero(), BigDecimal::of('0.10'), BigDecimal::zero())]), taxableShare: '80');
})->throws(ImplausibleTaxRate::class, 'Taxable share');
