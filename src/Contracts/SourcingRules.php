<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\ValueObjects\IntrastateSourcing;

/**
 * Supplies a US state's INTRASTATE sourcing rule — origin vs destination vs mixed —
 * the DATA a host needs to pick which local jurisdiction's rate applies to an
 * in-state supply. Like {@see NexusThresholds} it is a data seam, not engine logic:
 * a state with no known rule returns `null` (deny-by-default), and interstate
 * supplies are destination-sourced everywhere regardless of this.
 */
interface SourcingRules
{
    public function for(SubdivisionCode $state): ?IntrastateSourcing;
}
