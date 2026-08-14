<?php

declare(strict_types=1);

namespace Cbox\Tax\Charges;

use Cbox\Tax\Contracts\OrderFlatChargeSource;
use Cbox\Tax\ValueObjects\OrderAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;

/**
 * The shipped default: no document-level charges anywhere.
 *
 * The same honesty as {@see NoFlatCharges}, for the other seam. Per-delivery levies
 * — Colorado's Retail Delivery Fee, Minnesota's — are per-jurisdiction, move on
 * their own schedule, and no authoritative compilation of them sits behind this
 * package. A host that knows its own obligations binds a source that says so.
 *
 * A separate class rather than one implementing both contracts: their `chargesFor`
 * methods take different arguments, which is the point of having two seams.
 */
readonly class NoOrderFlatCharges implements OrderFlatChargeSource
{
    public function chargesFor(TaxOrder $order, OrderAssessment $assessment): array
    {
        return [];
    }
}
