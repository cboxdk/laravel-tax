<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\EuTerritories;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\PlaceOfSupplyRule;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\ValueObjects\EuTerritory;
use Cbox\Tax\ValueObjects\InvoiceMention;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;
use LogicException;

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
    public function __construct(
        private readonly ?JurisdictionRepository $jurisdictions = null,
        private readonly ?EuTerritories $territories = null,
    ) {}

    protected function label(): string
    {
        return 'EU VAT';
    }

    /**
     * Ten territories sit inside a Member State and outside its VAT rules.
     *
     * This is checked before anything else because it is not a rate question. A
     * delivery to Tenerife is an EXPORT from the EU VAT area, not a Spanish sale at
     * some other percentage — and charging 21% on it invents a liability while the
     * customer separately owes IGIC that nobody collected. No rate table can say
     * "this is not our tax"; only a place can.
     *
     * **The limit of this, stated plainly.** A supply INTO the territory from the
     * VAT area is an export and zero-rated, which is the overwhelming majority of
     * what an EU seller does. A supply BY a business established in the territory,
     * to a customer there, is not EU VAT at all — it is IGIC or IPSI, which this
     * engine does not model. The two cannot be told apart from a country code and
     * a postcode, so the reason names the local tax rather than pretending the
     * answer covers both.
     */
    public function assess(TaxQuery $query, TaxRateSource $rates): TaxAssessment
    {
        $territory = $this->territories?->for($query->place->country, $query->postalCode);

        if ($territory !== null && $territory->outsideVatArea) {
            return new TaxAssessment(
                treatment: TaxTreatment::ZeroRated,
                net: $query->amount,
                tax: $this->zero($query),
                gross: $query->amount,
                placeOfSupply: $query->place,
                rate: null,
                reason: sprintf(
                    'EU VAT: %s lies outside the EU VAT area, so this is an export and no EU VAT is due. %s applies there and is not computed by this engine.',
                    $territory->name,
                    $territory->ownTaxName ?? 'A local tax',
                ),
            );
        }

        if ($territory?->standardRate !== null) {
            return $this->assessInRegion($query, $rates, $territory);
        }

        return parent::assess($query, $rates);
    }

    /**
     * A supply into a region that stays inside the EU VAT area but sets its own
     * rates — the Azores at 16% and Madeira at 22%, where mainland Portugal is 23%.
     *
     * Only the STANDARD rate is substituted, because only the standard rate is
     * something a territory map can honestly carry: the map is stable for decades,
     * the rates are not, and a snapshot of moving figures is the thing this package
     * spends its time removing.
     *
     * A reduced-rate supply therefore keeps the mainland band, at LOW confidence
     * and with the shortfall named in the reason. That is a deliberate choice
     * between two wrongs. Madeira's reduced rate is 5% against the mainland's 6%,
     * the Azores' is 4% — so falling back OVER-charges by a point or two, which is
     * recoverable, where refusing the line would lose the sale outright. The
     * caller is told, rather than left to discover it on a return.
     */
    private function assessInRegion(TaxQuery $query, TaxRateSource $rates, EuTerritory $territory): TaxAssessment
    {
        $assessment = parent::assess($query, $rates);
        $rate = $assessment->rate;

        if ($rate === null || $rate->kind !== RateKind::Standard) {
            if ($rate === null) {
                return $assessment;
            }

            return $assessment->with(
                reason: sprintf(
                    '%s Charged at the mainland %s%% band: %s sets its own reduced rates and this engine carries only its standard rate, so the band may be up to two points high.',
                    $assessment->reason,
                    $rate->percentage,
                    $territory->name,
                ),
            );
        }

        $regional = new TaxRate(
            $territory->standardRate ?? throw new LogicException('A region without a standard rate cannot be assessed as one.'),
            RateKind::Standard,
            'eu-territory',
            Confidence::Derived,
        );

        [$net, $tax, $gross] = $this->split($query, $regional);

        return $assessment->with(
            net: $net,
            tax: $tax,
            gross: $gross,
            rate: $regional,
            reason: sprintf('EU VAT: %s%% in %s, which sets its own rate.', $regional->percentage, $territory->name),
        );
    }

    /**
     * Art. 226(11a): an invoice for a reverse-charged supply must carry the words
     * **"Reverse charge"**. Not a paraphrase, and not the audit-trail reason — the
     * CJEU held in *Luxury Trust Automobil* (C-247/21) that a missing mention
     * cannot be corrected retroactively, so a caller who prints something else has
     * an invoice that stays defective.
     *
     * The citation is Art. 196: the customer is liable for the VAT on a service
     * supplied by a taxable person not established in their Member State, which is
     * exactly the supply this branch has established. Where the reverse charge
     * rests on a different provision — a domestic reverse charge under Art. 199a,
     * say — the engine does not model it and therefore does not cite it.
     *
     * @return list<InvoiceMention>
     */
    protected function reverseChargeMentions(TaxQuery $query): array
    {
        return [
            new InvoiceMention(
                code: 'reverse_charge',
                text: 'Reverse charge',
                reference: 'Article 196 of Council Directive 2006/112/EC',
            ),
        ];
    }

    protected function sourcingPlace(TaxQuery $query): Jurisdiction
    {
        // Art. 45 first: for a consumer, the general rule for SERVICES is the
        // supplier's establishment. Only telecoms/broadcasting/electronic services
        // (Art. 58) and goods (Art. 33(a)) go to the customer, and treating those
        // carve-outs as the rule charged a German consultancy French VAT.
        $general = $this->generalRulePlace($query);

        if ($general !== null) {
            return $general;
        }

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
     * The supplier's establishment, when Art. 45's general rule governs this supply
     * — or null when it does not and the caller should fall through.
     *
     * Deliberately limited to the INTRA-EU case: both parties in the Community, so
     * the answer is unambiguous and was verified against the Directive directly. A
     * supplier established OUTSIDE the EU supplying general services to an EU
     * consumer is left on the existing destination treatment, because Art. 45 would
     * put that supply outside EU VAT entirely while Art. 59a lets a Member State
     * pull it back on effective-use-and-enjoyment grounds — a per-state option this
     * engine does not model. Getting that half-right would be worse than leaving it
     * where it is and saying so.
     */
    private function generalRulePlace(TaxQuery $query): ?Jurisdiction
    {
        if ($query->isBusiness() || $query->category->placeOfSupplyRule() !== PlaceOfSupplyRule::SupplierEstablishment) {
            return null;
        }

        $origin = $this->jurisdictions?->find($query->seller->establishment);

        if ($origin === null || ! $origin->taxProfile->isEuMember || ! $query->place->taxProfile->isEuMember) {
            return null;
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

        // Art. 59c disapplies Art. 33(a) and Art. 58 — and only those. It is relief
        // for intra-Community distance sales of goods and for TBE services, not a
        // general small-seller exemption. Granting it to, say, admission to an event
        // (Art. 53) charged origin VAT on a supply that is taxed where the event is.
        if ($query->category->placeOfSupplyRule() !== PlaceOfSupplyRule::Destination) {
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
