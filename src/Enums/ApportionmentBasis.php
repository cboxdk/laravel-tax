<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * How a delivery charge is spread across the supplies it delivers.
 *
 * Article 78(b) of the VAT Directive makes transport, packing, commission and
 * insurance charged by the supplier part of the taxable amount of the supply they
 * accompany. So delivery on a cart of books carries the books' reduced rate, not the
 * standard one — the charge has no rate of its own to look up.
 *
 * On a MIXED cart the directive stops there, and so, deliberately, do the member
 * states. HMRC's Notice 700/24 permits "any fair and reasonable apportionment, which
 * doesn't have to be based on price" — value, cost, weight or special delivery
 * requirements all qualify. Denmark's VAT act carries no explicit rule at all; the
 * market-price method is the main one, cost-based where separate prices are absent,
 * and a recent styresignal widened the choice further.
 *
 * **WHICH IS WHY THIS IS AN ARGUMENT AND NOT A CONSTANT.** Hardcoding one basis would
 * impose a choice that belongs to the taxpayer, and it is wrong in real cases: a heavy
 * zero-rated item shipped alongside a light standard-rated one makes weight defensible
 * where value over-allocates. What the law does require is that the basis be STATED
 * and applied consistently, so the one used is recorded on the assessment.
 *
 * A weight basis is legitimate and absent here because it needs per-line weights this
 * engine is not given. Adding it is additive — callers already pass a basis — and this
 * enum is the seam it arrives through.
 */
enum ApportionmentBasis: string
{
    /**
     * Pro rata by each line's net amount. The market-price method, and the main rule
     * in every guidance read for this — hence the default.
     */
    case NetValue = 'net_value';

    /**
     * An equal share to each delivered line, whatever it costs.
     *
     * Defensible where value tracks nothing about the cost of delivering: three
     * identical parcels, one holding a cheap heavy thing and one an expensive light
     * thing, cost the same to ship.
     */
    case Equal = 'equal';

    /** How the basis reads on an assessment, so the reason states it in words. */
    public function describe(): string
    {
        return match ($this) {
            self::NetValue => 'apportioned pro rata by net value',
            self::Equal => 'apportioned equally across the delivered lines',
        };
    }
}
