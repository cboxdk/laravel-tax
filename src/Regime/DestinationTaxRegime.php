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
use Cbox\Tax\ValueObjects\TaxRate;

/**
 * Shared logic for destination-based consumption taxes (VAT/GST): a cross-border
 * B2B supply to a tax-ID-validated customer reverse-charges (the customer
 * self-accounts, seller charges nothing); everything else is taxed at the place
 * of supply's rate. Concrete regimes differ only in labelling.
 */
abstract class DestinationTaxRegime implements TaxRegime
{
    use AppliesTaxRate;

    /** Short name of the regime, used in the assessment's human-readable reason. */
    abstract protected function label(): string;

    public function assess(TaxQuery $query, TaxRateSource $rates): TaxAssessment
    {
        if ($query->isCrossBorder() && $query->isBusiness() && $query->customerTaxIdValidated) {
            return $this->reverseCharge($query);
        }

        $rate = $rates->rateFor($query->place, $query->category);

        if ($rate === null) {
            throw UnresolvedTaxRate::for($query->place);
        }

        return $this->applyRate($query, $rate);
    }

    private function reverseCharge(TaxQuery $query): TaxAssessment
    {
        return new TaxAssessment(
            treatment: TaxTreatment::ReverseCharge,
            net: $query->amount,
            tax: $this->zero($query),
            gross: $query->amount,
            placeOfSupply: $query->place,
            rate: null,
            reason: sprintf(
                '%s reverse charge: cross-border B2B supply to a tax-registered customer in %s; customer self-accounts.',
                $this->label(),
                $query->place->country->value,
            ),
        );
    }

    private function applyRate(TaxQuery $query, TaxRate $rate): TaxAssessment
    {
        [$net, $tax, $gross] = $this->split($query, $rate);

        if ($rate->isZero()) {
            return new TaxAssessment(
                treatment: TaxTreatment::ZeroRated,
                net: $net,
                tax: $tax,
                gross: $gross,
                placeOfSupply: $query->place,
                rate: $rate,
                reason: sprintf('%s: zero-rated in %s.', $this->label(), $query->place->country->value),
            );
        }

        $scope = $query->isCrossBorder() ? 'destination' : 'domestic';

        return new TaxAssessment(
            treatment: TaxTreatment::Standard,
            net: $net,
            tax: $tax,
            gross: $gross,
            placeOfSupply: $query->place,
            rate: $rate,
            reason: sprintf(
                '%s: %s tax at %s%% in %s.',
                $this->label(),
                $scope,
                $rate->percentage,
                $query->place->country->value,
            ),
        );
    }
}
