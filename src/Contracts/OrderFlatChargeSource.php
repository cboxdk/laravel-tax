<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Tax\ValueObjects\FlatCharge;
use Cbox\Tax\ValueObjects\OrderAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;

/**
 * Supplies the fixed charges a whole DOCUMENT attracts — the ones levied once per
 * transaction however many lines it has.
 *
 * This is separate from {@see FlatChargeSource} because the two answer genuinely
 * different questions, and conflating them produced a wrong number. Colorado's
 * Retail Delivery Fee is $0.31 per DELIVERY; Minnesota's is $0.50. Applied through
 * the per-supply seam they were charged once per line, so a two-line order paid
 * $0.62 for one delivery. No amount of care inside a per-supply source can fix
 * that: it is handed one line at a time and cannot see that the lines share a
 * delivery.
 *
 * Both seams exist because both kinds are real. A per-supply charge (a deposit or
 * a levy that attaches to a particular item) belongs on the line; a per-delivery
 * fee belongs here. A source is handed the finished {@see OrderAssessment} for the
 * same reason the per-supply one is handed an assessment: applicability usually
 * turns on the OUTCOME — Colorado's fee is due on a delivery that contains taxable
 * goods, which is not knowable from the order alone.
 */
interface OrderFlatChargeSource
{
    /**
     * @return list<FlatCharge>
     */
    public function chargesFor(TaxOrder $order, OrderAssessment $assessment): array;
}
