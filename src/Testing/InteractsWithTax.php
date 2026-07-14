<?php

declare(strict_types=1);

namespace Cbox\Tax\Testing;

use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;

/**
 * Test helper: build a calculator with the shipped regimes and a chosen rate map.
 * Dogfooded by this package's own suite.
 */
trait InteractsWithTax
{
    /**
     * @param  array<string, string>|null  $rates  Country code → percentage; null uses the built-in defaults.
     */
    protected function taxCalculator(?array $rates = null): TaxCalculator
    {
        return new DefaultTaxCalculator(
            DefaultRegimeRegistry::withDefaults(),
            new StaticTaxRateSource($rates),
        );
    }
}
