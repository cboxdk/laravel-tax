<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Cbox\Tax\Contracts\TaxTable;
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
     * The share of the price the tax is computed on, as a percentage: `80` taxes
     * 80% of the price. Null is the whole price, which is nearly every rate.
     *
     * KEPT BESIDE THE PERCENTAGE, NOT FOLDED INTO IT. Texas taxes data processing at
     * its 6.25% on 80% of the price, and its cities, counties and transit authorities
     * take their shares on the same 80%. Multiplying that out to a 5% "rate" would
     * be a figure no statute names, the components would stop summing to it, and a
     * return filed per authority would carry rates nobody publishes. So the
     * percentage and its components stay the statutory ones, and every place that
     * turns a rate into money reads {@see effectivePercentage()} instead.
     */
    public ?BigDecimal $taxableShare;

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
        /**
         * Which published data answered this, precisely enough to find the
         * assessment again after that data is corrected.
         *
         * Null for a static table or a source that publishes nothing to trace back
         * to. Added last, like every parameter before it, so positional callers are
         * unaffected.
         */
        public ?RateProvenance $provenance = null,
        /**
         * The table the tax is, where a state writes it as one rather than as a
         * percentage. The percentage is then the table's per-unit figure, kept for
         * display and for the amounts the table cannot price.
         *
         * Null for every rate that is a percentage, which is nearly all of them.
         */
        public ?TaxTable $schedule = null,
        /**
         * The share of the price that bears the tax; see {@see $taxableShare}. Added
         * last, like every parameter before it.
         */
        BigDecimal|string|int|null $taxableShare = null,
    ) {
        $this->percentage = BigDecimal::of($percentage);
        $this->taxableShare = $taxableShare === null ? null : BigDecimal::of($taxableShare);

        // A share is of the price: above 100 would tax more than was sold, and zero
        // or below is an exemption, which the register files as one rather than as a
        // share. A table and a share together is a combination no state publishes
        // and that this class could not price consistently: a table is read on the
        // sale, and a document reads it on the sum of its lines.
        if ($this->taxableShare !== null && (! $this->taxableShare->isPositive() || $this->taxableShare->isGreaterThan(100) || $schedule !== null)) {
            throw ImplausibleTaxRate::share($this->taxableShare, $source);
        }

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

    /**
     * The rate as it bears on the WHOLE price: the percentage, scaled by the share of
     * the price that is taxed. 8.25% on 80% of the price is 6.6% of it.
     *
     * This is the figure to turn into money, and only that. It is not a rate any
     * authority publishes, so it is never shown as one and never split into
     * components; {@see $percentage} is.
     */
    public function effectivePercentage(): BigDecimal
    {
        if ($this->taxableShare === null) {
            return $this->percentage;
        }

        // Exact: a share divides by 100 by moving the point two places.
        return $this->percentage->multipliedBy($this->taxableShare)->dividedByExact(100);
    }

    /**
     * The part of an amount the tax is computed on — the amount itself, unless only a
     * share of the price is taxed. What a per-authority line reports as its base.
     */
    public function taxablePartOf(Money $amount): Money
    {
        if ($this->taxableShare === null) {
            return $amount;
        }

        return $amount->toRational()->multipliedBy($this->taxableShare)->dividedBy(100)
            ->toContext($amount->getContext(), RoundingMode::HalfUp);
    }

    /** The same rate, computed on a share of the price; see {@see $taxableShare}. */
    public function withTaxableShare(BigDecimal|string|int|null $share): self
    {
        return new self(
            $this->percentage,
            $this->kind,
            $this->source,
            $this->confidence,
            $this->components,
            $this->limitedBy,
            $this->provenance,
            $this->schedule,
            $share,
        );
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
    /**
     * The same rate, qualified: no longer authoritative, and saying why. An existing
     * limit is kept — it is the nearer thing to act on — and a rate already at low
     * confidence stays there.
     */
    public function qualifiedBy(RateLimit $limit): self
    {
        return new self(
            $this->percentage,
            $this->kind,
            $this->source,
            $this->confidence === Confidence::Authoritative ? Confidence::Derived : $this->confidence,
            $this->components,
            $this->limitedBy ?? $limit,
            $this->provenance,
            $this->schedule,
            $this->taxableShare,
        );
    }

    public function degraded(string $why): self
    {
        return new self(
            $this->percentage,
            $this->kind,
            $this->source.' (degraded: '.$why.')',
            Confidence::LowConfidence,
            $this->components,
            $this->limitedBy,
            $this->provenance,
            $this->schedule,
            $this->taxableShare,
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
    public function taxOnNet(Money $net, ?TaxRounding $rounding = null): Money
    {
        // A state that writes its tax as a table is priced by the table; the
        // percentage is only its per-unit figure.
        $scheduled = $this->schedule?->taxOn($net);

        if ($scheduled !== null) {
            return $scheduled;
        }

        // On a share of the price, the share scales the rate: 8.25% of 80% of the
        // price is 6.6% of it, still rounded once, at the money.
        $tax = $net->toRational()
            ->multipliedBy($this->effectivePercentage())
            ->dividedBy(100);

        return $rounding?->round($tax, $net->getContext())
            ?? $tax->toContext($net->getContext(), RoundingMode::HalfUp);
    }

    /**
     * The net amount contained within a gross (tax-inclusive) amount.
     *
     * `gross × 100 ÷ (100 + percentage)`, kept rational for the same reason as
     * {@see taxOnNet()}: one rounding step, and it happens at the money. On a share
     * of the price the effective percentage stands in for the percentage, because
     * the tax added to the net was the effective percentage of it.
     */
    public function netFromGross(Money $gross, ?TaxRounding $rounding = null): Money
    {
        $percentage = $this->effectivePercentage();

        if ($rounding !== null) {
            $tax = $gross->toRational()->multipliedBy($percentage)
                ->dividedBy(BigDecimal::of(100)->plus($percentage));

            return $gross->minus($rounding->round($tax, $gross->getContext()));
        }

        return $gross->toRational()
            ->multipliedBy(100)
            ->dividedBy(BigDecimal::of(100)->plus($percentage))
            ->toContext($gross->getContext(), RoundingMode::HalfUp);
    }
}
