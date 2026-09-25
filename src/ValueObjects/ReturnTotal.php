<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Tax\Enums\TaxTreatment;

/**
 * One treatment's share of a return line: what was supplied under it, and what tax
 * it carried.
 *
 * A period's total for a country is not a filing. A Dutch return asks separately for
 * domestic supplies, exempt intra-Community supplies of goods, services the customer
 * reverse-charges and supplies somebody else remitted — and the EC Sales List reports
 * goods and services in different columns. Summing them into one net and one tax
 * produces a number that reconciles with the invoices and fits no box on the form.
 */
readonly class ReturnTotal
{
    public function __construct(
        public TaxTreatment $treatment,
        public Money $net,
        public Money $tax,
        public int $count,
    ) {}

    /** The same treatment, with one more supply added to it. */
    public function plus(Money $net, Money $tax): self
    {
        return new self($this->treatment, $this->net->plus($net), $this->tax->plus($tax), $this->count + 1);
    }
}
