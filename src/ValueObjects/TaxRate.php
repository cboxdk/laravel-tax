<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Exceptions\ImplausibleTaxRate;
use Cbox\Tax\Exceptions\RateComponentsDoNotReconcile;

/**
 * A tax rate as a percentage (e.g. 25 = 25%), plus provenance: which band it is,
 * where the number came from, and how much to trust it. Rate maths lives here so
 * every regime rounds consistently.
 *
 * `components` optionally carries the authorities the rate is made of — the US
 * state share plus each local record a rooftop lookup stacked. It is the SOURCE's
 * knowledge, kept rather than discarded: only whoever summed the rate knows how it
 * decomposes, and a consumer cannot recover the split from the total afterwards.
 *
 * An EMPTY list means "this source cannot decompose this rate", not "one authority
 * takes it all" — deny-by-default, the same stance the engine takes on a missing
 * rate. When components ARE supplied they must sum exactly to `percentage`; see
 * {@see RateComponentsDoNotReconcile} for why that is enforced here.
 */
readonly class TaxRate
{
    public BigDecimal $percentage;

    /**
     * @param  list<RateComponent>  $components  The authorities this rate is made
     *                                           of; empty when the source cannot
     *                                           decompose it.
     *
     * @throws ImplausibleTaxRate When the percentage is outside 0–100.
     * @throws RateComponentsDoNotReconcile When the components do not sum to the rate.
     */
    public function __construct(
        BigDecimal|string|int $percentage,
        public RateKind $kind = RateKind::Standard,
        public string $source = 'static',
        public Confidence $confidence = Confidence::Authoritative,
        public array $components = [],
        /**
         * Why this is the best available answer rather than the exact one, and what
         * would close the gap. Null when the rate is exact for what was asked.
         *
         * ADDED LAST so every existing positional caller keeps working — the same
         * discipline the territory rates and the scope map were added under.
         *
         * It sits beside {@see Confidence} rather than replacing it because they
         * answer different questions. Confidence decides whether to bill; this
         * decides what to do about it, and a warning nobody can act on is one
         * everybody learns to filter out.
         */
        public ?RateLimit $limitedBy = null,
    ) {
        $this->percentage = BigDecimal::of($percentage);

        // A rate outside 0–100% is not a rate, it is corrupt data. Left unchecked a
        // negative percentage produces NEGATIVE tax (money handed back on every
        // invoice) and an absurd one over-collects, both stamped with whatever
        // confidence the source claimed.
        //
        // Note what this does NOT catch: a source that forgets to multiply a
        // fraction by 100 passes silently — `new TaxRate('0.0725')` is a valid
        // 0.0725%, and under-collects by 100×. There is no floor to add, because
        // special-district rates genuinely run to 0.125%. Sources validate their
        // own scaling; this is the ceiling, not a unit check.
        if ($this->percentage->isNegative() || $this->percentage->isGreaterThan(100)) {
            throw ImplausibleTaxRate::for($this->percentage, $source);
        }

        if ($components === []) {
            return;
        }

        $sum = BigDecimal::zero();

        foreach ($components as $component) {
            $sum = $sum->plus($component->percentage);
        }

        // Numeric comparison, so 7.2500 and 7.25 reconcile — scale is presentation.
        if (! $sum->isEqualTo($this->percentage)) {
            throw RateComponentsDoNotReconcile::for($this->percentage, $sum);
        }
    }

    public function isZero(): bool
    {
        return $this->percentage->isZero();
    }

    /** Whether the source decomposed this rate into its taxing authorities. */
    public function hasComponents(): bool
    {
        return $this->components !== [];
    }

    /**
     * The same rate, marked as the product of a degraded resolution.
     *
     * Used when a fallback source answered because an authoritative one was
     * UNAVAILABLE — not because it had nothing to say. The figure is unchanged and
     * may well be right; what changed is how much it is worth trusting, and a
     * caller that bills on it should be able to see that before it does.
     *
     * The reason is appended to the source so it survives into the assessment,
     * where the only thing carrying it would otherwise be a log line nobody reads.
     */
    public function degraded(string $why): self
    {
        return new self(
            $this->percentage,
            $this->kind,
            $this->source.' (degraded: '.$why.')',
            Confidence::LowConfidence,
            $this->components,
        );
    }

    /**
     * Tax due on a net (tax-exclusive) amount.
     *
     * ROUNDED ONCE, at the minor unit. An earlier version divided the percentage
     * by 100 to a fixed twelve decimal places first and multiplied by that
     * quotient, which put a second rounding step upstream of the money.
     *
     * To be honest about the size of it: that step was exact for every rate this
     * package can realistically be handed, because a percentage with ten or fewer
     * decimals divides by 100 exactly within twelve. Making the two answers differ
     * takes a fifteen-decimal rate against roughly $100bn. It is not a defect that
     * was costing anyone a cent — it is one less place for a rounding decision to
     * live, and the rational form says what the arithmetic is without the reader
     * having to check whether a scale is wide enough.
     */
    public function taxOnNet(Money $net): Money
    {
        return $net->toRational()
            ->multipliedBy($this->percentage)
            ->dividedBy(100)
            ->toContext($net->getContext(), RoundingMode::HalfUp);
    }

    /**
     * The net amount contained within a gross (tax-inclusive) amount.
     *
     * `gross × 100 ÷ (100 + percentage)`, kept rational for the same reason as
     * {@see taxOnNet()}: one rounding step, and it happens at the money.
     */
    public function netFromGross(Money $gross): Money
    {
        return $gross->toRational()
            ->multipliedBy(100)
            ->dividedBy(BigDecimal::of(100)->plus($this->percentage))
            ->toContext($gross->getContext(), RoundingMode::HalfUp);
    }
}
