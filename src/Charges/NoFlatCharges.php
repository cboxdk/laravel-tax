<?php

declare(strict_types=1);

namespace Cbox\Tax\Charges;

use Cbox\Tax\Contracts\FlatChargeSource;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * The shipped default: no fixed charges anywhere.
 *
 * Honest rather than empty-by-oversight. These levies are per-jurisdiction, change
 * on their own schedule, and no authoritative compilation of them sits behind this
 * package — so the default states plainly that the engine knows of none, and a host
 * that knows its own obligations binds a source that says so.
 */
readonly class NoFlatCharges implements FlatChargeSource
{
    public function chargesFor(TaxQuery $query, TaxAssessment $assessment): array
    {
        return [];
    }
}
