<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\Tests\TestCase;
use Cbox\Tax\ValueObjects\RateBand;

uses(TestCase::class)->in(__DIR__);

/**
 * A line amount for a taxability assertion that is not about the amount.
 *
 * Most categories answer the same at any price. The three that do not — clothing
 * in MA, NY and RI — get their own tests with the prices that matter.
 */
function anyAmount(string $amount = '50.00', string $currency = 'USD'): Money
{
    return Money::of($amount, $currency);
}

/**
 * A rate source over a register built for one test.
 *
 * The suite's shared fixture covers most of it; this is for the cases that need a
 * jurisdiction to charge something specific, or nothing at all.
 *
 * @param  array<string, string>  $rates  Country or `US-XX` code → percentage
 * @param  array<string, RateBand>  $bands  "<jurisdiction>:<tax class>" → band
 */
function rateSourceFor(array $rates, array $bands = []): RegisterRateSource
{
    return new RegisterRateSource(test()->registerWith($rates, $bands));
}
