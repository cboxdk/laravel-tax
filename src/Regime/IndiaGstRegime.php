<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime;

use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Contracts\TaxRegime;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\UnresolvedTaxRate;
use Cbox\Tax\Regime\Concerns\AppliesTaxRate;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * India GST (dual model). The customer-facing rate is uniform across the split, so
 * the tax amount is a single rate; this regime adds India's correct component
 * labelling and reverse-charge behaviour:
 *
 *  - Intra-state (seller and buyer in the same Indian state) → CGST + SGST.
 *  - Inter-state, imports, and foreign (OIDAR) suppliers → IGST.
 *  - A cross-border B2B supply to a GST-registered recipient reverse-charges (the
 *    recipient self-accounts for IGST). A foreign B2C (OIDAR) supply is charged by
 *    the supplier at the destination IGST rate.
 *
 * The rate itself (e.g. 18% standard) is sourced via the rate source.
 */
readonly class IndiaGstRegime implements TaxRegime
{
    use AppliesTaxRate;

    public function assess(TaxQuery $query, TaxRateSource $rates): TaxAssessment
    {
        if ($query->isCrossBorder() && $query->isBusiness() && $query->customerTaxIdValidated) {
            return new TaxAssessment(
                treatment: TaxTreatment::ReverseCharge,
                net: $query->amount,
                tax: $this->zero($query),
                gross: $query->amount,
                placeOfSupply: $query->place,
                rate: null,
                reason: 'India GST: cross-border B2B supply to a GST-registered recipient; recipient self-accounts for IGST under reverse charge.',
            );
        }

        $rate = $rates->rateFor($query->place, $query->category);

        if ($rate === null) {
            throw UnresolvedTaxRate::for($query->place);
        }

        [$net, $tax, $gross] = $this->split($query, $rate);

        $component = $this->isIntraState($query) ? 'CGST+SGST' : 'IGST';

        return new TaxAssessment(
            treatment: TaxTreatment::Standard,
            net: $net,
            tax: $tax,
            gross: $gross,
            placeOfSupply: $query->place,
            rate: $rate,
            reason: sprintf('India GST: %s at %s%% (destination).', $component, $rate->percentage),
        );
    }

    /**
     * Intra-state when the buyer's Indian state is known and the seller holds a
     * registration in that same state; otherwise the supply is treated as
     * inter-state / import (IGST).
     */
    private function isIntraState(TaxQuery $query): bool
    {
        $buyerState = $query->place->subdivision;

        if ($buyerState === null) {
            return false;
        }

        return $query->seller->isRegisteredInSubdivision($buyerState);
    }
}
