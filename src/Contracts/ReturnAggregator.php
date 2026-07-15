<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxReturn;

/**
 * Aggregates assessments into return-data — net and tax totals per jurisdiction
 * and currency — ready for filing.
 */
interface ReturnAggregator
{
    /**
     * @param  iterable<TaxAssessment>  $assessments
     */
    public function aggregate(iterable $assessments): TaxReturn;
}
