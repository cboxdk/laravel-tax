<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\ValueObjects\NexusThreshold;

/**
 * Supplies the economic-nexus threshold for a US state — the *Wayfair*
 * remote-seller trigger. It is a DATA seam like {@see TaxRateSource}: bind a
 * source backed by an authoritative, dated compilation, and re-verify it as
 * states amend their thresholds.
 *
 * A state with no published/known threshold returns `null` — the engine then
 * makes no economic-nexus claim for it (deny-by-default: nexus must be asserted
 * by an explicit seller registration), never a guess.
 */
interface NexusThresholds
{
    public function for(SubdivisionCode $state): ?NexusThreshold;
}
