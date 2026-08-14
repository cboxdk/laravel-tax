<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Tax\Returns\DefaultReturnAggregator;

/**
 * A whole document's verdict: every line's assessment, and the totals.
 *
 * **Totals are summed from the lines, never recomputed.** Each line is rounded
 * once, when it is assessed, and the document total is the exact sum of those
 * rounded figures. The alternative — totalling the nets and applying a rate to the
 * sum — produces a number that does not equal the invoice rows beneath it, and an
 * invoice whose lines do not add up to its total is the one thing a customer
 * always notices.
 */
readonly class OrderAssessment
{
    /**
     * @param  list<LineAssessment>  $lines  Non-empty: a {@see TaxOrder} cannot be built without lines.
     * @param  list<FlatCharge>  $charges  Levies on the document as a whole, not on any one line.
     */
    public function __construct(public array $lines, public array $charges = []) {}

    /**
     * A copy carrying the document-level charges.
     *
     * @param  list<FlatCharge>  $charges
     */
    public function withCharges(array $charges): self
    {
        return new self($this->lines, $charges);
    }

    public function net(): Money
    {
        return $this->sum(static fn (TaxAssessment $a): Money => $a->net);
    }

    public function tax(): Money
    {
        return $this->sum(static fn (TaxAssessment $a): Money => $a->tax);
    }

    public function gross(): Money
    {
        return $this->sum(static fn (TaxAssessment $a): Money => $a->gross);
    }

    public function forLine(string $id): ?TaxAssessment
    {
        foreach ($this->lines as $line) {
            if ($line->id === $id) {
                return $line->assessment;
            }
        }

        return null;
    }

    /**
     * Every line's assessment, in order.
     *
     * This is what feeds {@see DefaultReturnAggregator}, which takes
     * `iterable<TaxAssessment>` and needs no change to accept a document: a return
     * is built from supplies, and a document is a set of supplies.
     *
     * @return list<TaxAssessment>
     */
    public function assessments(): array
    {
        return array_map(
            static fn (LineAssessment $line): TaxAssessment => $line->assessment,
            $this->lines,
        );
    }

    /**
     * The document-level charges the buyer is billed for — excluding any the seller
     * must absorb, and excluding anything levied on an individual line.
     *
     * Null when there are none, since there is no currency to total in.
     */
    public function chargesTotal(): ?Money
    {
        $total = null;

        foreach ($this->charges as $charge) {
            if (! $charge->passedToBuyer) {
                continue;
            }

            $total = $total === null ? $charge->amount : $total->plus($charge->amount);
        }

        return $total;
    }

    /**
     * What the buyer pays for the whole document: the gross, plus each line's own
     * charges, plus the charges levied on the document.
     *
     * `gross()` stays the exact sum of the lines' `net + tax`, the same invariant a
     * single assessment keeps, so charges are added here rather than folded in.
     */
    public function payable(): Money
    {
        $payable = $this->gross();

        foreach ($this->lines as $line) {
            $lineCharges = $line->assessment->chargesTotal();

            if ($lineCharges !== null) {
                $payable = $payable->plus($lineCharges);
            }
        }

        $charges = $this->chargesTotal();

        return $charges === null ? $payable : $payable->plus($charges);
    }

    /**
     * The document's tax rolled up per taxing authority, for a per-jurisdiction
     * remittance.
     *
     * Null when ANY taxed line lacks a breakdown. A partial roll-up would be worse
     * than none: it looks like the document's split while silently omitting the
     * lines whose source could not decompose their rate, and the omission is
     * invisible precisely because the remaining figures still look reasonable.
     *
     * @return list<AuthorityTotal>|null
     */
    public function taxByAuthority(): ?array
    {
        /** @var array<string, AuthorityTotal> $totals */
        $totals = [];

        foreach ($this->lines as $line) {
            $assessment = $line->assessment;

            // A line that charged nothing has nothing to attribute, and no missing
            // breakdown to complain about.
            if ($assessment->tax->isZero()) {
                continue;
            }

            // An EMPTY breakdown on a taxed line is not a split, it is the absence
            // of one — the same distinction TaxRate draws for components. Merging
            // it as though it contributed nothing would drop that line's tax from
            // the roll-up while the remaining figures still looked plausible.
            if ($assessment->breakdown === null || $assessment->breakdown->isEmpty()) {
                return null;
            }

            foreach ($assessment->breakdown->lines as $index => $share) {
                // An unidentified authority cannot be merged with another: two
                // special districts both reporting a null code are two districts,
                // not one, and summing them would report a single district owed
                // both shares. Key those by position within their line instead, so
                // they stay distinct and simply do not combine across lines.
                $key = $share->code === null
                    ? $share->level->value.'|#'.$index.'@'.$line->id
                    : $share->level->value.'|'.$share->code;

                $totals[$key] = isset($totals[$key])
                    ? new AuthorityTotal(
                        $share->level,
                        $totals[$key]->tax->plus($share->tax),
                        $share->code,
                        $totals[$key]->name ?? $share->name,
                    )
                    : new AuthorityTotal($share->level, $share->tax, $share->code, $share->name);
            }
        }

        return array_values($totals);
    }

    /**
     * @param  callable(TaxAssessment): Money  $of
     */
    private function sum(callable $of): Money
    {
        $total = null;

        foreach ($this->lines as $line) {
            $amount = $of($line->assessment);
            $total = $total === null ? $amount : $total->plus($amount);
        }

        // A TaxOrder cannot be constructed without lines, so this cannot be null in
        // practice; the guard keeps the type honest rather than asserting.
        return $total ?? Money::zero('XXX');
    }
}
