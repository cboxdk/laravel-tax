<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * A jurisdiction's calculation LOGIC — place-of-supply, reverse-charge, rate
 * application, inclusive/exclusive. This is what the engine owns; a regime pulls
 * rate DATA from the {@see TaxRateSource} it is given but decides whether and how
 * to apply it.
 */
interface TaxRegime
{
    public function assess(TaxQuery $query, TaxRateSource $rates): TaxAssessment;
}
