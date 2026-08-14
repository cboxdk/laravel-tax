<?php

declare(strict_types=1);

namespace Cbox\Tax\Concerns;

use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\ValueObjects\FlatCharge;
use Cbox\Tax\ValueObjects\LineAssessment;
use Cbox\Tax\ValueObjects\OrderAssessment;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;

/**
 * Assessing a document as a fan-out over single supplies.
 *
 * Shared by the shipped calculator and by the adapter that wraps a host's own, so
 * the two cannot drift into answering a document differently. There is deliberately
 * no tax logic here: each line becomes a query via {@see TaxOrder::queryFor()} and
 * runs the identical path, which is what guarantees a document cannot reach an
 * outcome a single supply could not.
 *
 * A line that refuses throws, and the whole document fails with it. Half a
 * tax-assessed invoice is not a useful artefact — worse, it is one a caller might
 * ship.
 *
 * @phpstan-require-implements TaxCalculator
 */
trait AssessesOrders
{
    public function assessOrder(TaxOrder $order): OrderAssessment
    {
        $assessment = new OrderAssessment(array_map(
            fn (SupplyLine $line): LineAssessment => new LineAssessment(
                $line->id,
                $this->assessLine($order, $line),
            ),
            $order->lines,
        ));

        $charges = $this->orderCharges($order, $assessment);

        return $charges === [] ? $assessment : $assessment->withCharges($charges);
    }

    /**
     * Assess one line of the document.
     *
     * Overridable because a per-supply concern can be wrong at document level: the
     * shipped calculator suppresses per-supply flat charges here and levies the
     * document's own separately, so a per-delivery fee is charged once rather than
     * once per line.
     */
    protected function assessLine(TaxOrder $order, SupplyLine $line): TaxAssessment
    {
        return $this->assess($order->queryFor($line));
    }

    /**
     * The charges levied on the document as a whole. None by default — a host
     * calculator wrapped by the fan-out has no document-level source to ask.
     *
     * @return list<FlatCharge>
     */
    protected function orderCharges(TaxOrder $order, OrderAssessment $assessment): array
    {
        return [];
    }
}
