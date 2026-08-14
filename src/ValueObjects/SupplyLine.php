<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;

/**
 * One line of a supply document — the amount, what is being supplied, and any
 * line-specific overrides of the document's context.
 *
 * `amount` is the EXTENDED amount: unit price × quantity, less any discount
 * allocated to this line. The host computes it, and that is deliberate. Discount
 * allocation is commercial logic — which lines a promotion applies to, how a
 * whole-order discount splits across them, what rounds where — and a tax engine
 * that took `quantity`, `unitPrice` and `discount` would have to make those calls
 * on the host's behalf, silently, with money.
 *
 * `quantity` is likewise absent, and the reason is worth stating because the
 * mature APIs all carry it: they carry it because they support quantity-based
 * taxes (per-litre fuel duty, per-unit fees), which key off units rather than
 * value. This engine has no such tax, so a quantity field would be one nothing
 * reads — the failure mode {@see SourcingRules} already
 * demonstrates. It arrives with the tax that needs it, not before.
 *
 * `pricing` overrides the document's for this line alone. A subscription quoted
 * VAT-inclusive alongside metered usage quoted exclusive is an ordinary invoice,
 * and a single document-level setting cannot express it.
 */
readonly class SupplyLine
{
    public function __construct(
        /** The host's own line identifier, echoed back on the assessment. */
        public string $id,
        public Money $amount,
        public TaxClass $category = TaxClass::GeneralGoods,
        /** Null inherits the document's pricing. */
        public ?Pricing $pricing = null,
        public ?string $commodityCode = null,
        /** Overrides the document's exemption for this line alone. */
        public ?TaxExemption $exemption = null,
    ) {}
}
