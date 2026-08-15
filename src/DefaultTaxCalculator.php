<?php

declare(strict_types=1);

namespace Cbox\Tax;

use Brick\Money\Money;
use Cbox\Tax\Catalogue\EmptyProductCatalogue;
use Cbox\Tax\Concerns\AssessesOrders;
use Cbox\Tax\Contracts\FlatChargeSource;
use Cbox\Tax\Contracts\OrderFlatChargeSource;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\ProductCatalogue;
use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\UnsupportedJurisdiction;
use Cbox\Tax\ValueObjects\FlatCharge;
use Cbox\Tax\ValueObjects\InvoiceMention;
use Cbox\Tax\ValueObjects\OrderAssessment;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;

/**
 * The engine entry point. Reads the place of supply's tax profile (from geo),
 * selects the regime keyed by its `regimeModule`, and delegates. Deny-by-default:
 * an unmodelled jurisdiction or an unregistered regime is refused.
 *
 * A buyer exemption ({@see TaxQuery::$exemption}) is applied last, as a native
 * override of the regime's verdict: it exempts ONLY a would-be standard-taxed
 * supply, and only when the exemption is valid and covers the taxed jurisdiction.
 * Reverse-charge, not-registered, zero-rated and already-exempt outcomes are left
 * untouched — the regime's precedence wins, exactly as an app-layer decorator over
 * this calculator would behave, but now native.
 */
readonly class DefaultTaxCalculator implements OrderTaxCalculator
{
    use AssessesOrders;

    public function __construct(
        private RegimeRegistry $registry,
        private TaxRateSource $rates,
        private ?FlatChargeSource $charges = null,
        private ?OrderFlatChargeSource $documentCharges = null,
        /**
         * Resolves your item codes to tax classes. Defaults to one that knows
         * nothing, so an app that never sets an item code behaves exactly as before.
         */
        private ProductCatalogue $catalogue = new EmptyProductCatalogue,
    ) {}

    public function assess(TaxQuery $query): TaxAssessment
    {
        // Last, and deliberately so: a fixed charge usually turns on the OUTCOME —
        // Colorado's fee is due on a delivery that contains taxable goods — so the
        // source has to see whether tax was charged before it can answer.
        [$query, $unmapped] = $this->classify($query);

        return $this->flagUnmapped($this->applyCharges($query, $this->assessSupply($query)), $unmapped);
    }

    /**
     * Fill in the class and commodity code from the product catalogue, and report
     * whether the item code was one nothing has mapped.
     *
     * MOST SPECIFIC WINS, and the order is the whole contract: a class stated on the
     * query is an override for the line in hand and is never second-guessed; then
     * the catalogue, keyed by the item code; then whatever the query already
     * carried, which is the shipped default.
     *
     * A stated class is detected by it NOT being the default. That is a real
     * limitation and it is stated rather than hidden: a caller who explicitly passes
     * `TaxClass::GeneralGoods` for a line they mean to be general goods will still
     * have the catalogue consulted. The alternative — a nullable class — would make
     * every existing caller construct a query differently, and the outcome only
     * differs for an item whose mapping is also general goods.
     *
     * @return array{TaxQuery, bool}
     */
    private function classify(TaxQuery $query): array
    {
        if ($query->itemCode === null || $query->itemCode === '') {
            return [$query, false];
        }

        if ($query->category !== TaxClass::GeneralGoods) {
            return [$query, false];
        }

        $mapping = $this->catalogue->find($query->itemCode);

        if ($mapping === null) {
            // Still priced, still invoiced — and reported, so the SKU can be found
            // and mapped rather than taxed at the fallback in silence forever.
            return [$query, true];
        }

        // The line's own code still wins: a caller who knows this particular supply
        // is classified differently from the product in general has said so
        // deliberately.
        return [$query->classifiedAs($mapping->class, $query->commodityCode ?? $mapping->commodityCode), false];
    }

    /**
     * Record on the rate that the class came from a fallback rather than a mapping.
     *
     * Only where nothing else already limits the answer. An assessment that is
     * already flagged ambiguous has a more specific story to tell, and overwriting
     * it with the coarser one would send a reviewer to the wrong remedy.
     */
    private function flagUnmapped(TaxAssessment $assessment, bool $unmapped): TaxAssessment
    {
        if (! $unmapped || $assessment->rate === null || $assessment->rate->limitedBy !== null) {
            return $assessment;
        }

        return $assessment->with(rate: new TaxRate(
            $assessment->rate->percentage,
            $assessment->rate->kind,
            $assessment->rate->source,
            $assessment->rate->confidence,
            $assessment->rate->components,
            RateLimit::ItemUnmapped,
        ));
    }

    /**
     * A line of a document is assessed WITHOUT its per-supply flat charges.
     *
     * A flat charge is levied on a TRANSACTION, and a document is one transaction.
     * Run through the per-supply path a per-delivery fee was applied once per line,
     * so a two-line Colorado order paid $0.31 twice for a single delivery. The
     * document's own charges come from {@see OrderFlatChargeSource} instead, once.
     */
    protected function assessLine(TaxOrder $order, SupplyLine $line): TaxAssessment
    {
        return $this->assessSupply($order->queryFor($line));
    }

    /**
     * The tax verdict for one supply: regime, dates, exemption. Everything except
     * the flat charges, which differ between a standalone supply and a line of a
     * document — so this is the part the two paths genuinely share.
     */
    private function assessSupply(TaxQuery $query): TaxAssessment
    {
        $module = $query->place->taxProfile->regimeModule;

        if ($module === null) {
            throw UnsupportedJurisdiction::for($query->place->country);
        }

        $regime = $this->registry->for($module);

        if ($regime === null) {
            throw UnsupportedJurisdiction::for($query->place->country);
        }

        return $this->applyExemption($query, $this->stampTaxPoint($query, $regime->assess($query, $this->rates)));
    }

    /**
     * @return list<FlatCharge>
     */
    protected function orderCharges(TaxOrder $order, OrderAssessment $assessment): array
    {
        return $this->documentCharges?->chargesFor($order, $assessment) ?? [];
    }

    /**
     * Attach the fixed charges a bound source says this supply attracts.
     *
     * No source bound, or none applicable, and the assessment is returned
     * untouched — which is what every caller got before charges existed.
     */
    private function applyCharges(TaxQuery $query, TaxAssessment $assessment): TaxAssessment
    {
        $charges = $this->charges?->chargesFor($query, $assessment) ?? [];

        return $charges === [] ? $assessment : $assessment->with(charges: $charges);
    }

    /**
     * Record the date the assessment resolved against.
     *
     * Stamped here rather than in each regime so the five of them cannot drift, and
     * so a host binding its own regime gets it for free. A regime that already set
     * one keeps it — it knows something the calculator does not.
     */
    private function stampTaxPoint(TaxQuery $query, TaxAssessment $assessment): TaxAssessment
    {
        if ($assessment->taxPoint !== null && $assessment->reportedOn !== null) {
            return $assessment;
        }

        // Each date is filled only if the regime left it unset — a regime that set
        // one knows something the calculator does not.
        return $assessment->with(
            taxPoint: $assessment->taxPoint ?? $query->on(),
            reportedOn: $assessment->reportedOn ?? $query->reportingDate(),
        );
    }

    /**
     * Rewrite a would-be standard-taxed line to a native `Exempt` assessment when
     * the query carries a valid exemption that covers the place the supply was
     * taxed in. Deny-by-default: no exemption, a non-standard treatment, an
     * expired/not-yet-valid exemption, or one that does not cover the place of
     * supply all leave the regime's assessment unchanged.
     */
    private function applyExemption(TaxQuery $query, TaxAssessment $assessment): TaxAssessment
    {
        $exemption = $query->exemption;

        if ($exemption === null || $assessment->treatment !== TaxTreatment::Standard) {
            return $assessment;
        }

        $place = $assessment->placeOfSupply;

        // Tested at the TAX POINT, not at calculation time: a certificate that has
        // since expired was still valid when the supply was made, and a credit note
        // issued today must not retroactively un-exempt it.
        if (! $exemption->appliesTo($place, $query->on())) {
            return $assessment;
        }

        $where = $place->subdivision !== null ? $place->subdivision->value : $place->country->value;

        return new TaxAssessment(
            treatment: TaxTreatment::Exempt,
            net: $assessment->net,
            tax: Money::zero($assessment->net->getCurrency(), $assessment->net->getContext()),
            gross: $assessment->net,
            placeOfSupply: $place,
            rate: null,
            reason: sprintf('Exempt: %s covers %s; standard tax overridden.', $exemption->describe(), $where),
            exemption: $exemption,
            taxPoint: $assessment->taxPoint,
            reportedOn: $assessment->reportedOn,
            // The certificate reference belongs ON the invoice: it is what a buyer's
            // exemption rests on, and what an auditor asks to see beside the zero.
            mentions: [new InvoiceMention(
                code: 'exempt_certificate',
                text: sprintf('Exempt — %s certificate %s', $exemption->type->label(), $exemption->reference),
            )],
        );
    }
}
