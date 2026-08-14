<?php

declare(strict_types=1);

namespace Cbox\Tax;

use Cbox\Tax\Concerns\AssessesOrders;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * Gives document support to ANY {@see TaxCalculator}, by fanning a document out
 * over its single-supply `assess()`.
 *
 * This exists because rebinding `TaxCalculator` is a documented, supported thing
 * for a host to do — it is how you swap in your own engine — and it must not cost
 * you documents. Without this, the container's `OrderTaxCalculator` binding would
 * either type-error on the host's calculator or silently hand back the shipped one,
 * which is worse: the host's own tax logic would be bypassed for every multi-line
 * invoice while single supplies kept using it.
 *
 * Nothing is lost by wrapping: a document has never been more than its lines.
 */
readonly class FanOutOrderCalculator implements OrderTaxCalculator
{
    use AssessesOrders;

    public function __construct(private TaxCalculator $inner) {}

    public function assess(TaxQuery $query): TaxAssessment
    {
        return $this->inner->assess($query);
    }
}
