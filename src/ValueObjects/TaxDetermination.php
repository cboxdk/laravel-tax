<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Enums\TaxabilityTreatment;
use Cbox\Tax\Enums\ThresholdRule;
use Cbox\Tax\Exceptions\ThresholdCurrencyMismatch;

/**
 * What a jurisdiction does to a product category — and, where the answer depends
 * on the price, what portion of a given amount is actually taxed.
 *
 * This replaced a boolean, because a boolean could not say what the data already
 * knew. Three states exempt clothing below a per-item price, and the seam that
 * answered `bool` had two options for them: charge full tax on every exempt
 * garment, or refuse the line. It refused, which for a checkout is a lost sale
 * over a rule we had the figures for all along.
 *
 * @see ProductTaxability
 */
readonly class TaxDetermination
{
    public function __construct(
        public TaxabilityTreatment $treatment,
        /**
         * A rate the jurisdiction sets for this category specifically, as a
         * percentage string — Tennessee's 4% on groceries. Null means the ordinary
         * rate for the place applies.
         */
        public ?string $reducedRate = null,
        /** Per-item price below which the category is exempt, in minor units. */
        public ?int $exemptBelowMinor = null,
        /** How that threshold applies once an item reaches it. */
        public ?ThresholdRule $thresholdRule = null,
        /**
         * The currency the threshold is STATED in — the one the statute names.
         *
         * A threshold is a number and a currency, and carrying only the number
         * makes it mean whatever the invoice happens to be denominated in. New
         * York's $110 arriving as `11000` against a JPY invoice becomes ¥11,000,
         * roughly seventy dollars, and against a BHD one (three decimal places)
         * it becomes 11 dinar. Both are answers to a question the statute did not
         * ask, and neither looks wrong on the invoice.
         */
        public ?string $thresholdCurrency = null,
    ) {}

    public static function taxable(): self
    {
        return new self(TaxabilityTreatment::Taxable);
    }

    public static function exempt(): self
    {
        return new self(TaxabilityTreatment::Exempt);
    }

    public static function reducedAt(string $percentage): self
    {
        return new self(TaxabilityTreatment::ReducedRate, $percentage);
    }

    public static function belowThreshold(int $minorUnits, ThresholdRule $rule, string $currency): self
    {
        return new self(TaxabilityTreatment::Conditional, null, $minorUnits, $rule, $currency);
    }

    /**
     * The portion of `$amount` that tax is actually charged on.
     *
     * Zero means the supply is exempt — which is not the same as a zero rate, and
     * the caller should report it as exempt rather than as tax of nothing.
     *
     * For a threshold category this is where the two mechanics diverge:
     * `ExcessTaxable` returns the amount minus the threshold, `Cliff` returns the
     * whole amount. Below the threshold both return zero.
     */
    public function taxableBase(Money $amount): Money
    {
        if ($this->treatment === TaxabilityTreatment::Exempt) {
            return Money::zero($amount->getCurrency(), $amount->getContext());
        }

        if ($this->exemptBelowMinor === null || $this->thresholdRule === null) {
            return $amount;
        }

        $currency = $amount->getCurrency();

        // A price threshold is only comparable against a price in the SAME money.
        // Converting would need a rate on the supply date that this package does
        // not have and should not invent, and reinterpreting the minor units — the
        // silent behaviour this replaced — turns New York's $110 into ¥110 or 110
        // fils depending on the invoice. Refusing puts the choice in front of the
        // host, which is the only place it can be made honestly.
        if ($this->thresholdCurrency !== null && $this->thresholdCurrency !== $currency->getCurrencyCode()) {
            throw ThresholdCurrencyMismatch::between($this->thresholdCurrency, $currency->getCurrencyCode());
        }

        $threshold = Money::ofMinor($this->exemptBelowMinor, $currency, $amount->getContext());

        // Compare the MAGNITUDE, not the signed amount. A credit note is a negative
        // supply of the same garment, and −$200 is arithmetically "less than $175"
        // while being nothing of the sort: read that way, a refund of a taxed
        // sweater returns the price and keeps the tax. The seller is left holding
        // money the customer is owed and the state will want reconciled, and
        // nothing about the figures looks wrong.
        $magnitude = $amount->abs();

        // Strictly below the threshold is exempt; reaching it is not. New York is
        // explicit that $109.99 is exempt and $110.00 is not, and an inclusive
        // comparison here would move a whole cent's worth of transactions to the
        // wrong side of the line.
        if ($magnitude->isLessThan($threshold)) {
            return Money::zero($amount->getCurrency(), $amount->getContext());
        }

        return match ($this->thresholdRule) {
            // The excess, carrying the original sign, so a refund credits exactly
            // what the sale charged.
            ThresholdRule::ExcessTaxable => $amount->isNegative()
                ? $magnitude->minus($threshold)->negated()
                : $amount->minus($threshold),
            ThresholdRule::Cliff => $amount,
        };
    }

    /** Whether nothing at all is taxed on this amount. */
    public function isExemptFor(Money $amount): bool
    {
        return $this->taxableBase($amount)->isZero();
    }

    /** Whether only part of the amount bears tax — the case a net/gross pair alone cannot show. */
    public function isPartial(Money $amount): bool
    {
        $base = $this->taxableBase($amount);

        return ! $base->isZero() && $base->isLessThan($amount);
    }
}
