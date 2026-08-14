<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime\Concerns;

use Brick\Math\BigDecimal;
use Brick\Money\AllocationMode;
use Brick\Money\Money;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\ValueObjects\BreakdownLine;
use Cbox\Tax\ValueObjects\RateComponent;
use Cbox\Tax\ValueObjects\TaxBreakdown;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;

/**
 * Shared rate maths for regimes: split an amount into net/tax/gross honouring
 * tax-exclusive vs tax-inclusive pricing, build a zero amount in the query's
 * currency/context, and split the resulting tax across the authorities that levy
 * it when the rate source decomposed the rate.
 */
trait AppliesTaxRate
{
    /**
     * @param  Money|null  $taxableBase  The portion of the amount tax is charged on,
     *                                   when it is less than the whole. Null means
     *                                   the whole amount, which is the usual case.
     * @return array{Money, Money, Money} net, tax, gross
     */
    protected function split(TaxQuery $query, TaxRate $rate, ?Money $taxableBase = null): array
    {
        if ($query->pricing === Pricing::Exclusive) {
            $net = $query->amount;
            $tax = $rate->taxOnNet($taxableBase ?? $net);
            $gross = $net->plus($tax);

            return [$net, $tax, $gross];
        }

        $gross = $query->amount;

        if ($taxableBase === null) {
            $net = $rate->netFromGross($gross);

            return [$net, $gross->minus($net), $gross];
        }

        // A tax-inclusive price where only PART of the amount bears tax. The
        // exempt portion passes through untouched, so removing tax from the whole
        // gross would strip tax that was never added to it:
        //
        //   gross = net + rate x (net - threshold)
        //   net   = (gross + rate x threshold) / (1 + rate)
        //
        // The exempt slice is the difference between the amount and its taxable
        // base, which is what the caller already resolved for us.
        $exempt = $query->amount->minus($taxableBase);
        $taxableGross = $gross->minus($exempt);
        $taxableNet = $rate->netFromGross($taxableGross);
        $tax = $taxableGross->minus($taxableNet);
        $net = $gross->minus($tax);

        return [$net, $tax, $gross];
    }

    protected function zero(TaxQuery $query): Money
    {
        return Money::zero($query->amount->getCurrency(), $query->amount->getContext());
    }

    /**
     * Split an already-computed tax across the rate's taxing authorities.
     *
     * The total is ALLOCATED, never recomputed per authority. Applying each
     * authority's rate to the net independently rounds each share on its own, and
     * the shares then miss the invoiced tax by a minor unit or two — which is
     * exactly the error that makes a per-jurisdiction filing fail to reconcile.
     * Allocation distributes the real total, so the parts sum to the whole by
     * construction.
     *
     * The remainder goes to the largest fractional share (Hamilton), not to the
     * first line: order in the component list is a source's iteration order, not a
     * statement about who is owed the odd cent, and a 0%-rate authority must never
     * collect one.
     *
     * Returns null — "no breakdown" — rather than a degenerate one when the rate
     * carries no components, when nothing was taxed, or when the money uses an
     * auto-scaling context that has no minor unit to allocate in.
     */
    protected function breakdown(TaxRate $rate, Money $net, Money $tax): ?TaxBreakdown
    {
        if (! $rate->hasComponents() || $rate->isZero() || $tax->isZero()) {
            return null;
        }

        if (! $tax->getContext()->isFixedScale()) {
            return null;
        }

        $shares = $tax->allocate(
            array_map(static fn (RateComponent $c): BigDecimal => $c->percentage, $rate->components),
            AllocationMode::FloorToLargestRemainder,
        );

        $lines = [];

        foreach ($rate->components as $index => $component) {
            $lines[] = new BreakdownLine(
                level: $component->level,
                percentage: $component->percentage,
                taxableAmount: $net,
                tax: $shares[$index],
                code: $component->code,
                name: $component->name,
            );
        }

        return new TaxBreakdown($lines);
    }
}
