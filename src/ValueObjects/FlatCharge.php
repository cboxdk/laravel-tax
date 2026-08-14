<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Tax\Enums\JurisdictionLevel;

/**
 * A fixed monetary charge a jurisdiction levies on a supply, independent of its
 * value.
 *
 * {@see TaxRate} is a percentage and refuses to be anything else — the constructor
 * rejects a value outside 0–100 and the components must sum to it exactly. Those
 * invariants are right for a rate and they made a whole class of real charge
 * inexpressible: Colorado's Retail Delivery Fee is **$0.31 per order** from
 * 1 July 2026 and Minnesota's is $0.50, neither of which is a percentage of
 * anything. A caller could only fake one as a rate derived from that order's
 * total, which changes per order and fails the reconciliation check anyway.
 *
 * `passedToBuyer` is false where the seller must absorb the charge and may not
 * bill it on. That is a real category — some levies are the seller's own cost by
 * statute — and a charge the engine reported without saying so would end up on a
 * customer's invoice.
 */
readonly class FlatCharge
{
    public function __construct(
        /** Stable key for the levy — `co_retail_delivery_fee`. Branch on this. */
        public string $code,
        /** What it is called on an invoice or a return. */
        public string $name,
        public Money $amount,
        public JurisdictionLevel $level = JurisdictionLevel::State,
        /** False when the seller must bear it and may not bill it on. */
        public bool $passedToBuyer = true,
    ) {}
}
