<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * The engine entry point. Selects the regime for the query's place of supply and
 * returns a full assessment. Bind this contract and depend on it.
 */
interface TaxCalculator
{
    public function assess(TaxQuery $query): TaxAssessment;
}
