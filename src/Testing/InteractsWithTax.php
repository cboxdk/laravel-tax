<?php

declare(strict_types=1);

namespace Cbox\Tax\Testing;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\ValueObjects\RateBand;

/**
 * Test helper: build a calculator with the shipped regimes and a chosen rate map.
 * Dogfooded by this package's own suite.
 */
trait InteractsWithTax
{
    /**
     * @param  array<string, string>|null  $rates  Country code → percentage; null uses the built-in defaults.
     * @param  array<string, RateBand>  $bands  "<jurisdiction>:<category>" → reduced/zero band.
     */
    protected function taxCalculator(?array $rates = null, array $bands = []): TaxCalculator
    {
        return new DefaultTaxCalculator(
            DefaultRegimeRegistry::withDefaults(null, app(JurisdictionRepository::class)),
            new StaticTaxRateSource($rates, $bands),
        );
    }
}
