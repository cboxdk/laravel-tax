<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\CategoryKeyedTaxability;
use Cbox\Tax\Contracts\DeliveryRules;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Contracts\RoundingRules;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Contracts\TaxRegime;
use Cbox\Tax\Contracts\UsTaxFacts;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\RefusalReason;
use Cbox\Tax\Enums\SourcingMode;
use Cbox\Tax\Enums\TaxabilityTreatment;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\JurisdictionNotResolved;
use Cbox\Tax\Exceptions\UnresolvedTaxRate;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\RateSource\ResolvesRates;
use Cbox\Tax\Regime\Concerns\AppliesTaxRate;
use Cbox\Tax\Register\Reader\CategoryMap;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxDetermination;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;
use Cbox\Tax\ValueObjects\TaxRounding;
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

    /**
     * The registration scheme by which a host asserts that THIS seller elected
     * the state's remote-seller flat-rate program — an accepted Alabama SSUT
     * application, a filed Texas Form 01-799. The election rides on the
     * registration it modifies: its validity window is the election's window,
     * and asserting the scheme is asserting the program's eligibility terms are
     * met, which is the seller's fact, not the engine's to infer.
     */
    public const string REMOTE_ELECTION_SCHEME = 'remote-election';

    /** Distinct from plain 'us-tax-data' so an audit trail shows the election. */
    private const string ELECTION_SOURCE = 'us-tax-data:election';

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
        private ?UsTaxFacts $dataset = null,
        private ?RoundingRules $rounding = null,
        private ?DeliveryRules $delivery = null,
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

    /**
     * Whether the line is at or under a holiday's per-item cap.
     *
     * ALL-OR-NOTHING, and this is where the two mechanics would otherwise be
     * confused. The permanent clothing thresholds a few lines up exempt the first
     * $175 of a Massachusetts coat and tax the rest. A holiday cap qualifies the
     * whole item or none of it: at $100 in a $100-cap state the coat is untaxed, at
     * $100.01 it is taxed in full. Treating a holiday cap as a threshold would
     * exempt the first $100 of a coat the state charges full tax on.
     *
     * CURRENCY MUST MATCH, and a mismatch charges rather than refuses. The caps are
     * dollar figures in state statutes, so comparing a line billed in another
     * currency would need an exchange rate on the supply date — the same reason the
     * threshold path throws. Here it does NOT throw: a holiday is a few days of
     * relief, and refusing the whole assessment over one would break a checkout for
     * a supply that is perfectly taxable. It falls through to the ordinary rate,
     * which is what applies outside the holiday anyway.
     */
    private function qualifiesForHoliday(TaxQuery $query, int $cap, bool $capInclusive): bool
    {
        if ($query->amount->getCurrency()->getCurrencyCode() !== 'USD') {
            return false;
        }

        // ABSOLUTE VALUE, because a credit note carries a negative amount and every
        // negative is below every cap. Without this a $500 coat refunded inside a
        // $300 holiday window is assessed exempt, so nothing is credited back
        // against the tax originally collected and the seller keeps the state's
        // money. `TaxDetermination::taxableBase()` takes the same precaution for the
        // permanent thresholds twenty lines away; this path did not.
        $price = $query->amount->getAmount()->abs();

        // PER ARTICLE: a holiday cap is per item and the line carries quantity × price,
        // so the cap is scaled rather than the price divided — exact either way round.
        $cap *= $query->quantity;

        // Compared as decimals, not floats — `Money` carries a BigDecimal precisely
        // so this is exact.
        //
        // INCLUSIVE OR NOT IS PER STATUTE. Texas exempts clothing "less than $100",
        // so an item at exactly $100.00 is taxable; Florida's "$100 or less" exempts
        // it. Treating every cap as inclusive under-collected on every item landing
        // exactly on a "less than" line.
        return $capInclusive
            ? $price->isLessThanOrEqualTo($cap)
            : $price->isLessThan($cap);
    }

    private function deliveryExcluded(TaxQuery $query, SubdivisionCode $state): bool
    {
        $delivery = $query->delivery;

        // A BUYER'S CERTIFICATE COVERS THE WHOLE SALE, freight included, and it is
        // applied after this. The certificate had made the goods exempt, which turned
        // the freight into "delivery of exempt goods" — a rule most states do not
        // publish, so an ordinary wholesale order with shipping refused outright. With
        // a certificate that applies here the delivery rule has nothing to decide.
        if ($query->exemption?->appliesTo($query->place, $query->on())) {
            return false;
        }

        if ($delivery === null || $delivery->goodsTaxable === null) {
            throw new UnresolvedTaxRule('Delivery requires the taxability of the delivered goods.', RefusalReason::DeliveryFactsRequired);
        }

        $treatment = $this->delivery?->treatment($state, $delivery, $query->on());

        if ($treatment === null) {
            throw new UnresolvedTaxRule('No applicable delivery rule for '.$state->value.' on '.$query->on()->format('Y-m-d').'.');
        }

        if (! $treatment->included) {
            // A published decision has already tested the exclusion's conditions
            // against the facts supplied; a bare flag has not.
            if ($treatment->conditionsVerified) {
                return true;
            }

            if ($delivery->exclusionConditionsMet === null) {
                throw new UnresolvedTaxRule('Confirm delivery exclusionConditionsMet after checking the published conditions for '.$state->value.'.', RefusalReason::DeliveryFactsRequired);
            }

            if ($delivery->exclusionConditionsMet) {
                return true;
            }
        }

        if (! $delivery->goodsTaxable) {
            throw new UnresolvedTaxRule('Taxable delivery of exempt goods needs an independently classified delivery rate.');
        }

        return false;
    }

    private function determination(TaxQuery $query): TaxDetermination
    {
        if ($query->categoryKey === null) {
            $determination = $this->taxability->determine($query->place, $query->category, $query->amount, $query->on());
        } elseif ($this->taxability instanceof CategoryKeyedTaxability) {
            $determination = $this->taxability->determineKey($query->place, $query->categoryKey, $query->amount, $query->on());
        } else {
            throw new UnresolvedTaxRule(sprintf('The bound taxability source (%s) cannot answer a register category key, and "%s" was asked for.', $this->taxability::class, $query->categoryKey));
        }

        // The delivered goods were assessed before their freight. A per-item
        // exemption threshold must not be applied a second time to the freight's price.
        return $query->delivery === null
            ? $determination
            : new TaxDetermination(TaxabilityTreatment::Taxable, $determination->reducedRate);
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

        if ($query->delivery !== null && ! $query->amount->isZero() && ($facilitated || $query->seller->isRegisteredInSubdivision($subdivision, $query->on()))) {
            if ($this->deliveryExcluded($query, $subdivision)) {
                return new TaxAssessment(
                    treatment: TaxTreatment::Exempt,
                    net: $query->amount,
                    tax: $this->zero($query),
                    gross: $query->amount,
                    placeOfSupply: $query->place,
                    rate: null,
                    reason: 'US sales tax: delivery excluded from the taxable base; its published exclusion conditions were met.',
                );
            }
        }

        if ($facilitated) {
            // Taxability still decides. A marketplace collects nothing on an exempt
            // supply, and reporting it as facilitated would assert a tax that was
            // never due — a wrong return under a right charge.
            $determination = $this->determination($query);

            if (! $determination->isExemptFor($query->amount, $query->quantity)) {
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
                    $this->nexusHint($subdivision, $query->on()),
                ),
            );
        }

        // On the SUPPLY's date, the same one the rate is resolved against. Priced
        // with one year's rate and another year's taxability, an assessment is
        // internally inconsistent in a way that still looks like a number.
        $determination = $this->determination($query);

        // Exempt outright, or below a price threshold — Massachusetts under $175,
        // New York under $110, Rhode Island under $250. Both are "no tax", and
        // saying so beats a zero-rated assessment, which reads as a rate.
        if ($determination->isExemptFor($query->amount, $query->quantity)) {
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

        // A holiday is the last gate before a rate, because it does not change the
        // rate — it removes the supply from tax for a few days. Asked after
        // taxability so a supply that is already exempt is reported as exempt for
        // its own reason rather than credited to a weekend it did not need.
        $holiday = $this->dataset?->salesTaxHoliday(
            $subdivision->value,
            $query->category->value,
            $query->on()->format('Y-m-d'),
        );

        if ($query->delivery === null && $holiday !== null && $this->qualifiesForHoliday($query, $holiday['cap'], $holiday['capInclusive'])) {
            return new TaxAssessment(
                treatment: TaxTreatment::Exempt,
                net: $query->amount,
                tax: $this->zero($query),
                gross: $query->amount,
                placeOfSupply: $query->place,
                rate: null,
                reason: sprintf(
                    'US sales tax: exempt under %s\'s %s, which covers %s at or under $%d per item on %s.',
                    $subdivision->value,
                    $holiday['name'],
                    $query->category->value,
                    $holiday['cap'],
                    $query->on()->format('Y-m-d'),
                ),
            );
        }

        // An elected flat rate replaces RATE RESOLUTION, not the gates above it:
        // marketplace liability, nexus, taxability and holidays all spoke first.
        // Shipped from INSIDE the destination state the seller is not remote for
        // this supply — the physical presence these programs exclude — so the
        // ordinary path prices it, which is exactly what in-state presence owes.
        if ($query->seller->holdsSubdivisionScheme($subdivision, self::REMOTE_ELECTION_SCHEME, $query->on())
            && $query->route->shipFrom?->subdivision?->equals($subdivision) !== true) {
            $election = $this->dataset?->remoteSellerElection($subdivision->value, $query->on()->format('Y-m-d'));

            if ($election === null) {
                // The seller asserts an election nothing can price: a state with
                // no scheme, a dataset too old to carry the section, or a lapsed
                // annual determination (Texas republishes by each Jan 1). Pricing
                // as if the seller had never elected would quietly charge rates
                // the election replaced; assuming last year's figure would charge
                // one nobody published. Refuse, loudly.
                throw UnresolvedTaxRate::underElection($subdivision, 'remote-seller election');
            }

            return $this->assessUnderElection($query, $subdivision, $determination, $election);
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
            $rate = new TaxRate($determination->reducedRate, RateKind::Reduced, $rate->source, $rate->confidence, [], $rate->limitedBy, $rate->provenance);
        }

        // A SERVICE NOBODY PUBLISHED A RULE FOR. US states tax services only where they
        // enumerate them, so "nothing says it is exempt" is an assumption here in a
        // way it is not for goods. The figure stands — the recoverable direction —
        // and stops claiming to be authoritative.
        if ($determination->assumed && str_starts_with($query->categoryKey ?? CategoryMap::keyFor($query->category), 'services.')) {
            $rate = $rate->qualifiedBy(RateLimit::TaxabilityAssumed);
        }

        $base = $determination->taxableBase($query->amount, $query->quantity);
        $rounding = $this->roundingPolicy($subdivision, $query, $rate);
        [$net, $tax, $gross] = $this->split($query, $rate, $base, $rounding);

        return new TaxAssessment(
            treatment: $this->treatmentFor($rate),
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
            breakdown: $this->breakdown($rate, $query->pricing === Pricing::Inclusive ? $base->minus($tax) : $base, $tax),
            rounding: $rounding,
            unroundedTax: $rounding === null ? null : $this->unroundedTax($query, $rate, $base),
            taxableBase: $query->pricing === Pricing::Inclusive ? $base->minus($tax) : $base,
        );
    }

    /**
     * Price under the state's remote-seller scheme the seller elected.
     *
     * A category the state prices specially (a reduced grocery rate) is REFUSED
     * rather than flattened: the published flat figure is for the general base,
     * and neither over- nor under-collecting a special category is acceptable
     * to keep the flat rate convenient.
     *
     * @param  array{program: string, mechanic: string, ratePercent: string, statute: string}  $election
     */
    private function assessUnderElection(TaxQuery $query, SubdivisionCode $subdivision, TaxDetermination $determination, array $election): TaxAssessment
    {
        if ($determination->reducedRate !== null) {
            throw UnresolvedTaxRate::underElection($subdivision, $election['program']);
        }

        [$percent, $composition] = match ($election['mechanic']) {
            'flat_total' => [$election['ratePercent'], 'replacing all state and local rates'],
            'single_local_rate' => $this->composeSingleLocalRate($subdivision, $election, $query->on()),
            // A mechanic this engine does not know composes in a way it cannot
            // know — a future dataset speaking past an older engine refuses.
            default => throw UnresolvedTaxRate::underElection($subdivision, $election['program']),
        };

        $rate = new TaxRate($percent, RateKind::Standard, self::ELECTION_SOURCE, Confidence::Authoritative);
        $base = $determination->taxableBase($query->amount, $query->quantity);
        $rounding = $this->roundingPolicy($subdivision, $query, $rate);
        [$net, $tax, $gross] = $this->split($query, $rate, $base, $rounding);

        return new TaxAssessment(
            treatment: $this->treatmentFor($rate),
            net: $net,
            tax: $tax,
            gross: $gross,
            placeOfSupply: $query->place,
            rate: $rate,
            reason: sprintf(
                'US sales tax: %s%% in %s under the elected %s (%s), %s.',
                $rate->percentage,
                $subdivision->value,
                $election['program'],
                $election['statute'],
                $composition,
            ),
            breakdown: $this->breakdown($rate, $query->pricing === Pricing::Inclusive ? $base->minus($tax) : $base, $tax),
            rounding: $rounding,
            unroundedTax: $rounding === null ? null : $this->unroundedTax($query, $rate, $base),
            taxableBase: $query->pricing === Pricing::Inclusive ? $base->minus($tax) : $base,
        );
    }

    /**
     * Texas' mechanic: the elected figure replaces the LOCAL use taxes only,
     * and the state's own share still applies on top — read from the dataset
     * for the supply's date, never hard-coded.
     *
     * @param  array{program: string, mechanic: string, ratePercent: string, statute: string}  $election
     * @return array{0: string, 1: string}
     */
    private function composeSingleLocalRate(SubdivisionCode $subdivision, array $election, DateTimeImmutable $on): array
    {
        $statePercent = $this->dataset?->stateRatePercent($subdivision->value, $on);

        if ($statePercent === null) {
            throw UnresolvedTaxRate::underElection($subdivision, $election['program']);
        }

        $sum = (string) BigDecimal::of($statePercent)->plus(BigDecimal::of($election['ratePercent']));
        $percent = str_contains($sum, '.') ? (rtrim(rtrim($sum, '0'), '.') ?: '0') : $sum;

        return [
            $percent,
            sprintf('%s%% state plus the %s%% single local rate in lieu of local use taxes', $statePercent, $election['ratePercent']),
        ];
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
     * Remote supplies use destination. An identified intrastate route consults
     * the rule for the supply date. Origin and destination can be applied directly;
     * a missing rule or a mixed rule cannot select one place and refuses.
     * Hosts constructing a regime without a sourcing source retain destination.
     */
    private function sourcedFrom(TaxQuery $query, SubdivisionCode $subdivision): Jurisdiction
    {
        $origin = $query->route->origin();

        if ($origin === null || $origin->subdivision === null || ! $origin->subdivision->equals($subdivision)) {
            return $query->place;
        }

        $mode = $this->sourcing?->for($subdivision, $query->on())?->mode;

        if ($this->sourcing !== null && $mode === null) {
            throw new UnresolvedTaxRule('No applicable intrastate sourcing rule for '.$subdivision->value.' on '.$query->on()->format('Y-m-d').'.');
        }

        if ($mode === SourcingMode::Mixed) {
            throw new UnresolvedTaxRule('Mixed intrastate sourcing requires rules for each taxing authority in '.$subdivision->value.'.');
        }

        return $mode === SourcingMode::Origin
            ? $origin
            : $query->place;
    }

    private function roundingPolicy(SubdivisionCode $state, TaxQuery $query, TaxRate $rate): ?TaxRounding
    {
        $policy = $this->rounding?->for($state, $query->on())?->elected($query->roundingScope);

        if ($policy !== null && $policy->aggregatesLocal === null) {
            foreach ($rate->components as $component) {
                if ($component->level !== JurisdictionLevel::State) {
                    throw new UnresolvedTaxRule('The rounding policy does not specify how local tax shares are aggregated.');
                }
            }
        }

        return $policy;
    }

    /**
     * An advisory suffix naming the state's economic-nexus threshold, so an
     * unregistered seller is flagged to verify whether the *Wayfair* trigger has
     * been crossed. Empty when no threshold source is bound or the state has none.
     */
    private function nexusHint(SubdivisionCode $subdivision, DateTimeImmutable $at): string
    {
        $threshold = $this->nexusThresholds?->for($subdivision, $at);

        if ($threshold === null) {
            return '';
        }

        return sprintf(
            ' Economic-nexus threshold there is %s — verify whether you have crossed it and must register.',
            $threshold->describe(),
        );
    }
}
