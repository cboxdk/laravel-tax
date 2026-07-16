<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * EU VAT. Digital/B2C supplies are taxed at the customer's Member State rate
 * (destination); intra-EU B2B supplies to a VIES-validated customer reverse-charge.
 * Rates are sourced (e.g. from the EU Commission's TEDB feed) via the rate source.
 *
 * Threshold-aware place-of-supply (Art. 59c VAT Directive): a micro-business
 * established in a single Member State, below the €10,000 combined cross-border
 * B2C threshold and not opted into OSS, charges its OWN (origin) VAT on
 * cross-border B2C supplies to other Member States. Once it opts into OSS or
 * crosses the threshold (current or preceding year), the general destination rule
 * applies. B2B reverse-charge is unaffected.
 */
class EuVatRegime extends DestinationTaxRegime
{
    public function __construct(private readonly ?JurisdictionRepository $jurisdictions = null) {}

    protected function label(): string
    {
        return 'EU VAT';
    }

    protected function sourcingPlace(TaxQuery $query): Jurisdiction
    {
        if (! $this->qualifiesForOriginSourcing($query)) {
            return $query->place;
        }

        $origin = $this->jurisdictions?->find($query->seller->establishment);

        // Deny-by-default: only source at origin when we can confirm the seller is
        // established in an EU Member State; otherwise apply the destination rule.
        if ($origin === null || ! $origin->taxProfile->isEuMember) {
            return $query->place;
        }

        return $origin;
    }

    /**
     * Whether the Art. 59c micro-business relief applies: a cross-border B2C supply
     * to another EU Member State, where the seller has asserted a below-threshold,
     * non-opted OSS status. Anything else (B2B, non-EU destination, a domestic
     * supply, or no asserted status) falls through to destination taxation.
     */
    private function qualifiesForOriginSourcing(TaxQuery $query): bool
    {
        if ($query->isBusiness()) {
            return false;
        }

        if (! $query->place->taxProfile->isEuMember) {
            return false;
        }

        // A domestic supply already sources at the seller's country — no relief needed.
        if ($query->seller->isEstablishedIn($query->place->country)) {
            return false;
        }

        $oss = $query->seller->oss;

        // Relief must be affirmatively asserted (below threshold, not opted in);
        // absent a status, the seller is treated under the general destination rule.
        return $oss !== null && ! $oss->taxesAtDestination();
    }
}
