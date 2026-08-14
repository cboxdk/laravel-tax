<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Tax\ValueObjects\ReturnPeriod;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxReturn;

/**
 * Aggregates assessments into return-data — net and tax totals per jurisdiction
 * and currency — ready for filing.
 *
 * A return covers a PERIOD, and until it could be given one this produced a total
 * over whatever the caller happened to pass. Filtering here rather than upstream is
 * deliberate: the date that decides the period is on the assessment, so the caller
 * would otherwise have to re-derive a rule the engine already applied.
 */
interface ReturnAggregator
{
    /**
     * @param  iterable<TaxAssessment>  $assessments
     * @param  ReturnPeriod|null  $period  When given, assessments whose reporting
     *                                     date falls outside it are EXCLUDED, and
     *                                     the return records the window it covers.
     */
    public function aggregate(iterable $assessments, ?ReturnPeriod $period = null): TaxReturn;
}
