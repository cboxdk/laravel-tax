<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Brick\Math\BigDecimal;
use Cbox\Tax\ValueObjects\TaxRate;
use InvalidArgumentException;

/**
 * Raised when a {@see TaxRate}'s components do not sum to the rate itself.
 *
 * A breakdown that does not reconcile is worse than no breakdown at all: it is
 * shaped exactly like an authoritative per-authority split, so a consumer will
 * remit on it, and the shortfall only surfaces at audit. The invariant is
 * therefore enforced at construction — a source that cannot decompose a rate must
 * supply NO components, not approximate ones.
 *
 * A `LogicException` rather than a runtime denial: unlike a missing rate, this is
 * not a gap in sourced data the engine can honestly refuse over — it means the
 * source's own arithmetic is inconsistent, which is a defect in that source.
 */
class RateComponentsDoNotReconcile extends InvalidArgumentException
{
    public static function for(BigDecimal $percentage, BigDecimal $sum): self
    {
        return new self(sprintf(
            'Rate components sum to %s%% but the rate is %s%%. A source that cannot decompose a rate must supply no components rather than an approximate split.',
            $sum,
            $percentage,
        ));
    }
}
