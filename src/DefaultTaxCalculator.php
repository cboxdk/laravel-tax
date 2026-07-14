<?php

declare(strict_types=1);

namespace Cbox\Tax;

use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Exceptions\UnsupportedJurisdiction;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * The engine entry point. Reads the place of supply's tax profile (from geo),
 * selects the regime keyed by its `regimeModule`, and delegates. Deny-by-default:
 * an unmodelled jurisdiction or an unregistered regime is refused.
 */
readonly class DefaultTaxCalculator implements TaxCalculator
{
    public function __construct(
        private RegimeRegistry $registry,
        private TaxRateSource $rates,
    ) {}

    public function assess(TaxQuery $query): TaxAssessment
    {
        $module = $query->place->taxProfile->regimeModule;

        if ($module === null) {
            throw UnsupportedJurisdiction::for($query->place->country);
        }

        $regime = $this->registry->for($module);

        if ($regime === null) {
            throw UnsupportedJurisdiction::for($query->place->country);
        }

        return $regime->assess($query, $this->rates);
    }
}
