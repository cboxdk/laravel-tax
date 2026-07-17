<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Contracts\TaxRegime;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\JurisdictionNotResolved;
use Cbox\Tax\Exceptions\UnresolvedTaxRate;
use Cbox\Tax\Regime\Concerns\AppliesTaxRate;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

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

    public function __construct(
        private ProductTaxability $taxability,
        private ?NexusThresholds $nexusThresholds = null,
    ) {}

    public function assess(TaxQuery $query, TaxRateSource $rates): TaxAssessment
    {
        $subdivision = $query->place->subdivision;

        if ($subdivision === null) {
            throw JurisdictionNotResolved::needsSubdivision($query->place);
        }

        if (! $query->seller->isRegisteredInSubdivision($subdivision)) {
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

        if (! $this->taxability->isTaxable($query->place, $query->category)) {
            return new TaxAssessment(
                treatment: TaxTreatment::Exempt,
                net: $query->amount,
                tax: $this->zero($query),
                gross: $query->amount,
                placeOfSupply: $query->place,
                rate: null,
                reason: sprintf('US sales tax: %s is not taxable in %s.', $query->category->value, $subdivision->value),
            );
        }

        $rate = $rates->rateFor($query->place, $query->category);

        if ($rate === null) {
            throw UnresolvedTaxRate::for($query->place);
        }

        [$net, $tax, $gross] = $this->split($query, $rate);

        return new TaxAssessment(
            treatment: TaxTreatment::Standard,
            net: $net,
            tax: $tax,
            gross: $gross,
            placeOfSupply: $query->place,
            rate: $rate,
            reason: sprintf('US sales tax: %s%% in %s.', $rate->percentage, $subdivision->value),
        );
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
