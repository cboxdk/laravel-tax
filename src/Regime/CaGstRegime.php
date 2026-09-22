<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Contracts\TaxRegime;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\JurisdictionNotResolved;
use Cbox\Tax\Exceptions\UnresolvedTaxRate;
use Cbox\Tax\RateSource\ResolvesRates;
use Cbox\Tax\Regime\Concerns\AppliesTaxRate;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;

/**
 * Canadian GST/HST (+ provincial PST/QST). Unlike the US, Canada has no local
 * (municipal) sales tax, so a province-level (subdivision) resolution fully
 * determines the combined rate. A cross-border non-resident B2B supply to a
 * GST/HST-registered customer is self-assessed by the customer (reverse charge);
 * otherwise the province's combined rate applies.
 */
readonly class CaGstRegime implements TaxRegime
{
    use AppliesTaxRate;
    use ResolvesRates;

    public function assess(TaxQuery $query, TaxRateSource $rates): TaxAssessment
    {
        $subdivision = $query->place->subdivision;

        if ($subdivision === null) {
            throw JurisdictionNotResolved::needsSubdivision($query->place);
        }

        if ($query->isCrossBorder() && $query->isBusiness() && $query->customerTaxIdValidated) {
            return new TaxAssessment(
                treatment: TaxTreatment::ReverseCharge,
                net: $query->amount,
                tax: $this->zero($query),
                gross: $query->amount,
                placeOfSupply: $query->place,
                rate: null,
                reason: sprintf('Canadian GST/HST: cross-border B2B supply to a registered customer in %s; customer self-assesses.', $subdivision->value),
            );
        }

        $rate = $this->resolveRate($rates, $query);

        if ($rate === null) {
            throw UnresolvedTaxRate::for($query->place);
        }

        $rate = $this->withoutUnregisteredProvincialShare($rate, $query, $subdivision);

        [$net, $tax, $gross] = $this->split($query, $rate);

        return new TaxAssessment(
            treatment: TaxTreatment::Standard,
            net: $net,
            tax: $tax,
            gross: $gross,
            placeOfSupply: $query->place,
            rate: $rate,
            reason: sprintf('Canadian sales tax: %s%% in %s.', $rate->percentage, $subdivision->value),
            breakdown: $this->breakdown($rate, $net, $tax),
        );
    }

    /**
     * A provincial sales tax is the province's, and only a seller registered with the
     * province collects it.
     *
     * GST and HST are one federal registration: registered for GST is registered for
     * Ontario's 13%. A PST is not — British Columbia, Saskatchewan, Manitoba and
     * Quebec each register their own vendors — so a seller who holds only the federal
     * number charges the federal share and nothing else. The provincial share is
     * dropped rather than refused: the federal part is still owed, and the seller
     * who does hold the provincial permit says so the way a US seller states a state
     * permit, as a registration in that subdivision.
     */
    private function withoutUnregisteredProvincialShare(TaxRate $rate, TaxQuery $query, SubdivisionCode $subdivision): TaxRate
    {
        $federal = null;
        $provincial = false;

        foreach ($rate->components as $component) {
            if ($component->level === JurisdictionLevel::Country) {
                $federal = $component;
            } elseif ($component->level === JurisdictionLevel::State && ! $component->percentage->isZero()) {
                $provincial = true;
            }
        }

        if ($federal === null || ! $provincial || $query->seller->isRegisteredInSubdivision($subdivision, $query->on())) {
            return $rate;
        }

        return new TaxRate(
            $federal->percentage,
            $federal->percentage->isZero() ? RateKind::Zero : $rate->kind,
            $rate->source,
            $rate->confidence,
            [$federal],
            $rate->limitedBy,
            $rate->provenance,
        );
    }
}
