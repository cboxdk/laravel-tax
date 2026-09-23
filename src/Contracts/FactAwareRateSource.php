<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Tax\ValueObjects\DecisionFacts;

/**
 * A rate source that can read the facts of a supply — what the product is, who is
 * buying it, how it will be used — against the conditions a published rate carries.
 *
 * An optional capability, the way {@see CategoryKeyedRateSource} is: a source that
 * does not implement it is asked as before, and the facts simply do not reach it. The
 * source is RETURNED bound to the facts rather than handed them on every call, so the
 * three lookup methods every source already implements keep their signatures.
 */
interface FactAwareRateSource extends TaxRateSource
{
    /** The same source, answering for a supply with these facts. */
    public function withFacts(DecisionFacts $facts): self;
}
