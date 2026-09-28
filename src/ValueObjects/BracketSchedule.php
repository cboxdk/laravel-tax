<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * A state's sales tax written as a table rather than a percentage — Maryland,
 * Pennsylvania, Alabama and Idaho publish one.
 *
 * THE TABLE IS THE TAX, NOT AN APPROXIMATION OF IT. Maryland's statute states the
 * tax as the table: six cents on each exact dollar, then 1 cent where the excess is
 * at least 1 cent and under 17, 2 cents to 34, 3 cents to 51, and so on. On $1.34
 * that is 9 cents; six per cent rounded is 8. A seller priced by percentage remits a
 * different figure from the one the state computes, so the table is applied as
 * published — to the whole sale, which is what the statute taxes.
 */
readonly class BracketSchedule
{
    /**
     * @param  list<BracketRow>  $rows  The table up to one whole unit.
     * @param  list<BracketRow>  $aboveRows  The table for the part of a unit past an
     *                                       exact number of units; the same rows as
     *                                       `$rows` where the state publishes one table.
     */
    public function __construct(
        public string $currency,
        public array $rows,
        /** The tax on each exact whole unit past the first table: 0.06 per dollar. */
        public ?BigDecimal $perWholeUnit = null,
        public array $aboveRows = [],
    ) {}

    /**
     * The tax the table states on an amount; null where it states none — an amount in
     * a gap between rows, past one unit with no per-unit figure, or in another
     * currency. The caller falls back to the percentage and says so.
     *
     * A credit is the same table read on the amount's magnitude, signed back.
     */
    public function taxOn(Money $amount): ?Money
    {
        if ($amount->getCurrency()->getCurrencyCode() !== $this->currency || $this->rows === []) {
            return null;
        }

        $magnitude = $amount->getAmount()->abs();
        $tax = $this->lookup($this->rows, $magnitude);

        if ($tax === null && $magnitude->isGreaterThan($this->lastUpTo())) {
            if ($this->perWholeUnit === null) {
                return null;
            }

            $whole = $magnitude->toScale(0, RoundingMode::Down);
            $excess = $magnitude->minus($whole);
            // "Six cents on each exact dollar, plus" a table figure for the excess: an
            // exact number of dollars has no excess and adds nothing. Maryland's and
            // Alabama's excess tables start at one cent, so looking up nothing in them
            // found no row.
            $part = $excess->isZero() ? BigDecimal::zero() : $this->lookup($this->aboveRows === [] ? $this->rows : $this->aboveRows, $excess);
            $tax = $part === null ? null : $whole->multipliedBy($this->perWholeUnit)->plus($part);
        }

        if ($tax === null) {
            return null;
        }

        $money = Money::of($tax, $this->currency, $amount->getContext(), RoundingMode::Unnecessary);

        return $amount->isNegative() ? $money->negated() : $money;
    }

    /** @param  list<BracketRow>  $rows */
    private function lookup(array $rows, BigDecimal $amount): ?BigDecimal
    {
        foreach ($rows as $row) {
            if ($row->covers($amount)) {
                return $row->tax;
            }
        }

        return null;
    }

    private function lastUpTo(): BigDecimal
    {
        $last = BigDecimal::zero();

        foreach ($this->rows as $row) {
            $last = BigDecimal::max($last, $row->upTo);
        }

        return $last;
    }
}
