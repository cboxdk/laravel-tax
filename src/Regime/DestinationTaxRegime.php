<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime;

use Cbox\Geo\ValueObjects\Jurisdiction;
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
 * of supply's rate. Concrete regimes differ in labelling and in how they source
 * the place of supply (see {@see sourcingPlace()}).
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

        $place = $this->sourcingPlace($query);

        $rate = $rates->rateFor($place, $query->category);

        if ($rate === null) {
            throw UnresolvedTaxRate::for($place);
        }

        return $this->applyRate($query, $place, $rate);
    }

    /**
     * The jurisdiction whose rate applies and that is recorded as the place of
     * supply. Defaults to the customer's location (destination taxation); regimes
     * with an origin-sourcing exception (EU micro-business relief) override this.
     */
    protected function sourcingPlace(TaxQuery $query): Jurisdiction
    {
        return $query->place;
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

    private function applyRate(TaxQuery $query, Jurisdiction $place, TaxRate $rate): TaxAssessment
    {
        [$net, $tax, $gross] = $this->split($query, $rate);

        if ($rate->isZero()) {
            return new TaxAssessment(
                treatment: TaxTreatment::ZeroRated,
                net: $net,
                tax: $tax,
                gross: $gross,
                placeOfSupply: $place,
                rate: $rate,
                reason: sprintf('%s: zero-rated in %s.', $this->label(), $place->country->value),
            );
        }

        // Origin sourcing (EU micro-business relief) taxes at the seller's country
        // rather than the customer's; distinguish it from destination/domestic.
        if (! $place->country->equals($query->place->country)) {
            $scope = 'origin';
        } elseif ($query->isCrossBorder()) {
            $scope = 'destination';
        } else {
            $scope = 'domestic';
        }

        return new TaxAssessment(
            treatment: TaxTreatment::Standard,
            net: $net,
            tax: $tax,
            gross: $gross,
            placeOfSupply: $place,
            rate: $rate,
            reason: sprintf(
                '%s: %s tax at %s%% in %s.',
                $this->label(),
                $scope,
                $rate->percentage,
                $place->country->value,
            ),
        );
    }
}
