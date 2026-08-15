<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Contracts\TaxRegime;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\SourcingMode;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\JurisdictionNotResolved;
use Cbox\Tax\Exceptions\UnresolvedTaxRate;
use Cbox\Tax\RateSource\ResolvesRates;
use Cbox\Tax\Regime\Concerns\AppliesTaxRate;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxDetermination;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * US sales tax (destination sourcing for remote supplies). Three gates before a
 * rate is applied:
 *
 *  1. The place of supply must be resolved to a state (subdivision); rooftop-level
 *     resolution is expected upstream via an AddressGeocoder for local stacking.
 *  2. The seller must have nexus in that state (a registration) — otherwise there
 *     is no obligation to collect (`NotRegistered`).
 *  3. The product must be taxable in that state (SaaS/digital varies by state).
 *
 * Only then is the state (and, where resolved, local) rate applied. Nothing is
 * ever guessed: an unresolved state or a missing rate refuses.
 *
 * Nexus itself is asserted by an explicit seller registration; the regime never
 * infers it from a single invoice (economic nexus turns on the seller's
 * *cumulative* volume in the state, which one supply does not carry). When an
 * optional {@see NexusThresholds} source is supplied, a `NotRegistered` outcome is
 * annotated with the state's published economic-nexus threshold, so an operator is
 * flagged to check whether a registration obligation has been triggered.
 */
readonly class UsSalesTaxRegime implements TaxRegime
{
    use AppliesTaxRate;
    use ResolvesRates;

    public function __construct(
        private ProductTaxability $taxability,
        private ?NexusThresholds $nexusThresholds = null,
        private ?SourcingRules $sourcing = null,
        /**
         * Read for the per-state facts that are not rates: today, when each state's
         * marketplace-facilitator rule took effect. Nullable so an app running on
         * the static tables keeps working — it simply never applies the marketplace
         * treatment, which is the safe direction.
         */
        private ?UsTaxDataset $dataset = null,
    ) {}

    /**
     * Whether the state's marketplace-facilitator rule was in force on the supply's
     * date.
     *
     * Every state with a sales tax has one today — Missouri closed the set on
     * 2023-01-01 — so for a current supply this is all but always true. It is still
     * asked, and asked ON THE DATE, because a backdated invoice or a credit note
     * against an older one is priced under the law that applied then: a Missouri
     * sale from 2022 was the seller's to collect, and answering from today's map
     * would zero a charge that was really owed.
     *
     * REFUSES ON MISSING DATA rather than assuming the rule applies. Without the
     * dataset — or for a state it does not carry — the honest answer is that we do
     * not know, and the seller collecting is the recoverable error. Charging twice
     * is visible to the customer; not charging at all surfaces in an audit.
     */
    private function marketplaceLawInForce(string $state, DateTimeImmutable $on): bool
    {
        $from = $this->dataset?->marketplaceFacilitatorFrom($state);

        return $from !== null && $from <= $on->format('Y-m-d');
    }

    public function assess(TaxQuery $query, TaxRateSource $rates): TaxAssessment
    {
        $subdivision = $query->place->subdivision;

        if ($subdivision === null) {
            throw JurisdictionNotResolved::needsSubdivision($query->place);
        }

        // Asked BEFORE the seller's own registration, because the seller's nexus has
        // nothing to do with it. Where a marketplace is the liable party the tax is
        // its obligation whether or not this seller has any presence in the state,
        // and a seller who charges as well double-charges the customer.
        $facilitated = $query->marketplaceFacilitated
            && $this->marketplaceLawInForce($subdivision->value, $query->on());

        if ($facilitated) {
            // Taxability still decides. A marketplace collects nothing on an exempt
            // supply, and reporting it as facilitated would assert a tax that was
            // never due — a wrong return under a right charge.
            $determination = $this->taxability->determine(
                $query->place,
                $query->category,
                $query->amount,
                $query->on(),
            );

            if (! $determination->isExemptFor($query->amount)) {
                return new TaxAssessment(
                    treatment: TaxTreatment::MarketplaceFacilitated,
                    net: $query->amount,
                    tax: $this->zero($query),
                    gross: $query->amount,
                    placeOfSupply: $query->place,
                    rate: null,
                    reason: sprintf(
                        'US sales tax: %s holds the marketplace liable to collect on a facilitated sale; '
                        .'the seller charges nothing and most states still expect the sale reported in gross '
                        .'receipts and deducted as marketplace-facilitated.',
                        $subdivision->value,
                    ),
                );
            }
        }

        if (! $query->seller->isRegisteredInSubdivision($subdivision, $query->on())) {
            return new TaxAssessment(
                treatment: TaxTreatment::NotRegistered,
                net: $query->amount,
                tax: $this->zero($query),
                gross: $query->amount,
                placeOfSupply: $query->place,
                rate: null,
                reason: sprintf(
                    'US sales tax: seller has no nexus/registration in %s; no obligation to collect.%s',
                    $subdivision->value,
                    $this->nexusHint($subdivision),
                ),
            );
        }

        // On the SUPPLY's date, the same one the rate is resolved against. Priced
        // with one year's rate and another year's taxability, an assessment is
        // internally inconsistent in a way that still looks like a number.
        $determination = $this->taxability->determine(
            $query->place,
            $query->category,
            $query->amount,
            $query->on(),
        );

        // Exempt outright, or below a price threshold — Massachusetts under $175,
        // New York under $110, Rhode Island under $250. Both are "no tax", and
        // saying so beats a zero-rated assessment, which reads as a rate.
        if ($determination->isExemptFor($query->amount)) {
            return new TaxAssessment(
                treatment: TaxTreatment::Exempt,
                net: $query->amount,
                tax: $this->zero($query),
                gross: $query->amount,
                placeOfSupply: $query->place,
                rate: null,
                reason: $this->exemptReason($query, $subdivision, $determination),
            );
        }

        $from = $this->sourcedFrom($query, $subdivision);
        $rate = $this->resolveRate($rates, $query, $from);

        if ($rate === null) {
            throw UnresolvedTaxRate::for($from);
        }

        // A state that sets its own rate for this category — Tennessee's 4% on
        // groceries — overrides the general rate, but ONLY when the source has not
        // already accounted for it.
        //
        // A dataset-backed source resolves the reduced rate itself and STACKS the
        // locals on top, because a state's reduced grocery rate is its own share
        // and local food taxes still apply: Missouri's 1.225% plus the county's
        // food rate, not 1.225% all-in. Substituting here regardless threw the
        // local half away and undid exactly that, turning a correct 6.25% into
        // 4.00% — an under-collection wearing the state's own published figure.
        if ($determination->reducedRate !== null && $rate->kind !== RateKind::Reduced) {
            $rate = new TaxRate($determination->reducedRate, RateKind::Reduced, $rate->source, $rate->confidence);
        }

        $base = $determination->taxableBase($query->amount);
        [$net, $tax, $gross] = $this->split($query, $rate, $base);

        return new TaxAssessment(
            treatment: TaxTreatment::Standard,
            net: $net,
            tax: $tax,
            gross: $gross,
            placeOfSupply: $query->place,
            rate: $rate,
            reason: sprintf(
                'US sales tax: %s%% in %s%s%s.',
                $rate->percentage,
                $subdivision->value,
                $from === $query->place ? '' : ', sourced at the seller\'s location',
                $determination->isPartial($query->amount)
                    ? sprintf(', charged on %s of %s above the exemption threshold', $base->getAmount(), $net->getAmount())
                    : '',
            ),
            breakdown: $this->breakdown($rate, $base, $tax),
        );
    }

    /**
     * Why nothing is charged — and the two reasons are worth telling apart.
     *
     * "Not taxable here" is a property of the category. "Below the exemption
     * threshold" is a property of THIS line's price, and the next one at a higher
     * price will be taxed. An operator reading a return needs to know which.
     */
    private function exemptReason(TaxQuery $query, SubdivisionCode $subdivision, TaxDetermination $determination): string
    {
        if ($determination->exemptBelowMinor === null) {
            return sprintf('US sales tax: %s is not taxable in %s.', $query->category->value, $subdivision->value);
        }

        // Stated in the currency of the STATUTE, not of the invoice. "$110" is the
        // figure an operator can look up; the same integer read as the invoice's
        // minor units would print a number that appears nowhere in New York law.
        $currency = $determination->thresholdCurrency ?? $query->amount->getCurrency()->getCurrencyCode();

        return sprintf(
            'US sales tax: %s is exempt in %s below %s %s per item.',
            $query->category->value,
            $subdivision->value,
            Money::ofMinor($determination->exemptBelowMinor, $currency)->getAmount(),
            $currency,
        );
    }

    /**
     * The jurisdiction whose LOCAL rate applies.
     *
     * Destination almost always, and for every interstate supply without exception.
     * The carve-out is an INTRASTATE sale in a state that sources at the seller:
     * Texas, Arizona, Missouri, Ohio, Pennsylvania, Tennessee, Utah, Virginia and
     * Mississippi all tax a Houston-to-Houston sale at the Houston seller's rate,
     * not the buyer's, and getting that wrong is a 2% error in the seller's own
     * home state — the one they are most likely to be audited in.
     *
     * Three things must all hold, and any of them missing falls back to
     * destination rather than guessing:
     *
     *  1. a {@see SourcingRules} source is bound and knows the state's rule;
     *  2. the rule is `Origin` — `Mixed` states split by jurisdiction layer or
     *     seller type in ways a single place cannot express, so they stay on
     *     destination until the split is modelled;
     *  3. the seller's origin is supplied AND is in the same state, because
     *     interstate is destination-sourced everywhere regardless.
     */
    private function sourcedFrom(TaxQuery $query, SubdivisionCode $subdivision): Jurisdiction
    {
        $origin = $query->route->origin();

        if ($origin === null || $origin->subdivision === null || ! $origin->subdivision->equals($subdivision)) {
            return $query->place;
        }

        return $this->sourcing?->for($subdivision)?->mode === SourcingMode::Origin
            ? $origin
            : $query->place;
    }

    /**
     * An advisory suffix naming the state's economic-nexus threshold, so an
     * unregistered seller is flagged to verify whether the *Wayfair* trigger has
     * been crossed. Empty when no threshold source is bound or the state has none.
     */
    private function nexusHint(SubdivisionCode $subdivision): string
    {
        $threshold = $this->nexusThresholds?->for($subdivision);

        if ($threshold === null) {
            return '';
        }

        return sprintf(
            ' Economic-nexus threshold there is %s — verify whether you have crossed it and must register.',
            $threshold->describe(),
        );
    }
}
