<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Tax\Tests\TestCase;

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
