<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime;

use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Contracts\TaxRegime;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\JurisdictionNotResolved;
use Cbox\Tax\Exceptions\UnresolvedTaxRate;
use Cbox\Tax\Regime\Concerns\AppliesTaxRate;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

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
            reason: sprintf('Canadian GST/HST: %s%% in %s.', $rate->percentage, $subdivision->value),
        );
    }
}
