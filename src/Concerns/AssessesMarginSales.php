<?php

declare(strict_types=1);

namespace Cbox\Tax\Concerns;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\EuTerritoriesBySubdivision;
use Cbox\Tax\Enums\MarginMethod;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\RefusalReason;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\ThresholdCurrencyMismatch;
use Cbox\Tax\Exceptions\UnresolvedTaxRate;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\RateSource\ResolvesRates;
use Cbox\Tax\Register\Reader\CategoryMap;
use Cbox\Tax\ValueObjects\InvoiceMention;
use Cbox\Tax\ValueObjects\MarginResult;
use Cbox\Tax\ValueObjects\MarginSale;
use Cbox\Tax\ValueObjects\MarginSchemeRule;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;

/**
 * A supply the seller elected to tax under the margin scheme (Arts. 311–325 of the VAT
 * Directive; VATA 1994 s. 50A).
 *
 * IT DOES NOT GO THROUGH THE REGIME, because none of what a regime decides applies:
 *
 *  - WHERE. A margin sale is taxed where the dispatch begins (Art. 31/32). The
 *    distance-selling rule does not apply to it (Art. 35), so it is never the
 *    customer's country, never OSS, and never counts towards the EUR 10,000
 *    threshold; the intra-Community exemption does not apply either (Art. 139(3)), so
 *    a business customer changes nothing.
 *  - ON WHAT. The margin, tax included (Art. 315): selling price less purchase price,
 *    times rate / (100 + rate). A sale at a loss owes nothing and offsets nothing.
 *  - AT WHICH RATE, WHAT AN EXPORT DOES, WHAT THE INVOICE SAYS. The state's, read from
 *    the register's rule: Germany forces its standard rate, the United Kingdom taxes
 *    the margin on an export where the Union exempts it.
 *
 * Refused, never guessed: a service, goods the state shuts out, a state or a method
 * the register publishes no scheme for, a rule that does not say its rate.
 */
trait AssessesMarginSales
{
    use ResolvesRates;

    /** The fact a caller states where it wants the acquisition on record: bought without deductible VAT. */
    public const string ACQUIRED_WITHOUT_DEDUCTIBLE_VAT = 'margin.acquiredWithoutDeductibleVat';

    private function assessMarginSale(TaxQuery $query, MarginSale $sale): TaxAssessment
    {
        $key = $query->categoryKey ?? CategoryMap::keyFor($query->category);

        if (! CategoryMap::isGoods($key)) {
            throw new UnresolvedTaxRule(sprintf(
                'The margin scheme covers goods: second-hand goods, works of art, collectors\' items and antiques. "%s" is a service, so it cannot be sold under it.',
                $key,
            ), RefusalReason::MarginSchemeIneligible);
        }

        // The one condition only the seller can know, where it says so itself: the
        // scheme is open to goods bought without VAT it could deduct (Art. 314).
        if ($query->facts->has(self::ACQUIRED_WITHOUT_DEDUCTIBLE_VAT) && $query->facts->get(self::ACQUIRED_WITHOUT_DEDUCTIBLE_VAT) === false) {
            throw new UnresolvedTaxRule(
                'The margin scheme is open only to goods the dealer bought without VAT it can deduct: from a private person, an exempt seller or another dealer under the scheme (Art. 314 of Council Directive 2006/112/EC). This item was bought with deductible VAT, so it is sold under the normal rules.',
                RefusalReason::MarginSchemeIneligible,
            );
        }

        $origin = $this->marginOrigin($query);
        $country = $origin->country->value;

        $rule = $this->margins?->for($origin->country, $sale->goods, $sale->method, $query->on());

        if ($rule === null) {
            throw new UnresolvedTaxRule(sprintf(
                'The installed register publishes no margin scheme for %s under the %s method in %s on %s, so the sale cannot be taxed under it. Nothing is assumed from another state\'s scheme.',
                str_replace('_', ' ', $sale->goods->value),
                $sale->method->value,
                $country,
                $query->on()->format('Y-m-d'),
            ), RefusalReason::MarginSchemeUnavailable);
        }

        $this->refuseExcludedGoods($query, $sale, $rule, $country);

        $selling = $query->amount;
        $magnitude = $selling->abs()->minus($sale->purchasePrice);
        $margin = $magnitude->isNegative() ? $this->nothing($selling) : ($selling->isNegative() ? $magnitude->negated() : $magnitude);
        $union = $origin->taxProfile->isEuMember;
        $mentions = $rule->mention === null ? [] : [$rule->mention];

        if (! $this->insideMarginTerritory($origin, $query)) {
            // A treatment the publisher dates says nothing about the days before it:
            // the United Kingdom zero-rated a margin export until 1 May 2023.
            if ($rule->export === null || ($rule->exportFrom !== null && $query->on()->format('Y-m-d') < $rule->exportFrom)) {
                throw new UnresolvedTaxRule(sprintf(
                    'The installed register does not say what %s does with a margin sale that leaves its tax territory on %s. Sync a newer release, or sell the item under the normal rules.',
                    $country,
                    $query->on()->format('Y-m-d'),
                ), RefusalReason::MarginSchemeUnavailable);
            }

            // `zero_rated` is the same answer under the state's own word for it (Estonia,
            // Poland, Ireland reach no tax on the export through a nil rate).
            if ($rule->export === 'exempt' || $rule->export === 'zero_rated') {
                return new TaxAssessment(
                    treatment: TaxTreatment::ZeroRated,
                    net: $selling,
                    tax: $this->nothing($selling),
                    gross: $selling,
                    placeOfSupply: $origin,
                    rate: null,
                    reason: sprintf(
                        'Margin scheme (%1$s): goods dispatched from %1$s to a customer in %2$s, outside the European Union, are %3$s export even when sold under the margin scheme (Art. 321 with Art. 146(1) of Council Directive 2006/112/EC). No VAT is due on the margin; keep proof that the goods left the Union.',
                        $country,
                        $query->place->country->value,
                        $rule->export === 'zero_rated' ? 'a zero-rated' : 'an exempt',
                    ),
                    mentions: [new InvoiceMention('export', $rule->export === 'zero_rated' ? 'Zero-rated export of goods' : 'Exempt export of goods', 'Article 146(1) of Council Directive 2006/112/EC')],
                );
            }
        }

        $exported = ! $this->insideMarginTerritory($origin, $query);

        if ($sale->method === MarginMethod::Global) {
            return new TaxAssessment(
                treatment: TaxTreatment::MarginScheme,
                net: $selling,
                tax: $this->nothing($selling),
                gross: $selling,
                placeOfSupply: $origin,
                rate: null,
                reason: sprintf(
                    'Margin scheme (%s), per-period method: the VAT is computed on the return period\'s total sales less its total purchases, so this sale has no VAT figure of its own and none is shown here. It is taxed in %s, where the dispatch begins, and the invoice must not state VAT separately.',
                    $country,
                    $country,
                ),
                mentions: $mentions,
                limitedBy: RateLimit::MarginPerPeriod,
                margin: new MarginResult($sale->goods, $sale->method, $sale->purchasePrice, $margin, taxComputable: false),
            );
        }

        $rate = $this->marginRate($query, $origin, $rule);
        $marginNet = $rate->isZero() ? $margin : $rate->netFromGross($margin);
        $tax = $margin->minus($marginNet);

        return new TaxAssessment(
            treatment: TaxTreatment::MarginScheme,
            net: $selling->minus($tax),
            tax: $tax,
            gross: $selling,
            placeOfSupply: $origin,
            rate: $rate,
            reason: sprintf(
                'Margin scheme (%1$s): %2$s%% VAT is inside the margin of %3$s (selling price %4$s less purchase price %5$s)%6$s. %7$s%8$s The invoice must not state VAT separately%9$s.',
                $country,
                $rate->percentage,
                $margin->getAmount(),
                $selling->getAmount(),
                $sale->purchasePrice->getAmount(),
                $margin->isZero() ? ': nothing is due on a sale at or below what the item cost, and the loss offsets no other sale' : '',
                $union
                    ? sprintf('It is taxed in %s, where the dispatch begins: the distance-selling rule and the intra-Community exemption do not apply to margin sales (Arts. 35 and 139(3) of Council Directive 2006/112/EC), so it is not an OSS sale and does not count towards the EUR 10,000 threshold.', $country)
                    : sprintf('It is taxed in %s, where the seller makes the supply.', $country),
                $exported ? sprintf(' %s does not relieve a margin sale that leaves its territory: the VAT on the margin stays due, and only a sale under the normal rules is zero-rated as an export.', $country) : '',
                $union ? ' (Art. 325)' : '',
            ),
            mentions: $mentions,
            taxableBase: $marginNet,
            margin: new MarginResult($sale->goods, $sale->method, $sale->purchasePrice, $margin),
        );
    }

    /**
     * Where a margin sale is taxed: where the goods are when the dispatch begins. The
     * stated ship-from where there is one, else the seller's establishment — and the
     * seller must be established or registered there, or it has no return to put the
     * margin on.
     */
    private function marginOrigin(TaxQuery $query): Jurisdiction
    {
        $home = $this->jurisdictions?->find($query->seller->establishment);

        if ($home === null) {
            throw new UnresolvedTaxRule(sprintf(
                'A margin sale is taxed where the seller dispatches from, and %s could not be resolved as a place.',
                $query->seller->establishment->value,
            ), RefusalReason::MarginSchemeUnavailable);
        }

        $from = $query->route->shipFrom;

        if ($from === null || $from->country->equals($home->country)) {
            // The ship-from keeps its subdivision: Northern Ireland is told from Great Britain by it.
            return $from ?? $home;
        }

        if (! $query->seller->isRegisteredIn($from->country, $query->on())) {
            throw new UnresolvedTaxRule(sprintf(
                'A margin sale is taxed where the dispatch begins. These goods ship from %s, where the seller is neither established nor registered, so there is no return to declare the margin on. Add the registration, or correct the ship-from.',
                $from->country->value,
            ), RefusalReason::MarginSchemeUnavailable);
        }

        return $from;
    }

    private function refuseExcludedGoods(TaxQuery $query, MarginSale $sale, MarginSchemeRule $rule, string $country): void
    {
        $code = preg_replace('/\D/', '', $query->commodityCode ?? '') ?? '';

        foreach ($rule->excludedCodes as $heading) {
            if ($code !== '' && str_starts_with($code, $heading)) {
                throw new UnresolvedTaxRule(sprintf(
                    '%s shuts goods under tariff heading %s out of its margin scheme, and this line carries commodity code %s.',
                    $country,
                    $heading,
                    $code,
                ), RefusalReason::MarginSchemeIneligible);
            }
        }

        if ($rule->purchasePriceLimit === null || $rule->purchasePriceLimitCurrency === null) {
            return;
        }

        $currency = $sale->purchasePrice->getCurrency()->getCurrencyCode();
        $paid = $sale->purchasePrice;

        if ($currency !== $rule->purchasePriceLimitCurrency) {
            $converted = $this->exchangeRates?->rate($currency, $rule->purchasePriceLimitCurrency, $query->on())?->convert($paid);

            if ($converted === null) {
                throw ThresholdCurrencyMismatch::between($rule->purchasePriceLimitCurrency, $currency);
            }

            $paid = $converted;
        }

        // Per item: a line of three is three purchases.
        $limit = Money::of($rule->purchasePriceLimit, $rule->purchasePriceLimitCurrency)->multipliedBy(max(1, $query->quantity));

        if ($paid->isGreaterThan($limit)) {
            throw new UnresolvedTaxRule(sprintf(
                '%s allows the %s method only for an item bought for at most %s %s, and this one cost %s. Elect the individual method for it.',
                $country,
                $sale->method->value,
                $rule->purchasePriceLimitCurrency,
                $rule->purchasePriceLimit,
                $sale->purchasePrice->getAmount(),
            ), RefusalReason::MarginSchemeIneligible);
        }
    }

    /**
     * The rate the margin bears: the state's standard rate where its law forces it,
     * else the rate of the goods themselves, both read at the place of dispatch.
     */
    private function marginRate(TaxQuery $query, Jurisdiction $origin, MarginSchemeRule $rule): TaxRate
    {
        if ($rule->rate !== 'standard' && $rule->rate !== 'goods') {
            throw new UnresolvedTaxRule(sprintf(
                'The installed register publishes %s\'s margin scheme without saying which rate the margin bears%s. Sync a newer release; the engine will not choose between the goods\' rate and the standard rate.',
                $origin->country->value,
                $rule->rate === null ? '' : sprintf(' (it says "%s", which this engine does not read)', $rule->rate),
            ), RefusalReason::MarginSchemeUnavailable);
        }

        $asked = $rule->rate === 'standard'
            ? new TaxQuery($query->amount, $query->pricing, $origin, $query->customer, $query->seller, suppliedAt: $query->suppliedAt)
            : $query;

        $rate = $this->resolveRate($this->rates, $asked, $origin);

        if ($rate === null) {
            throw UnresolvedTaxRate::for($origin);
        }

        return $rate;
    }

    /**
     * Whether the sale stays inside the tax territory the scheme belongs to.
     *
     * For a member state that is the Union's VAT area — and Northern Ireland, where
     * the Union's rules on goods apply (Windsor Framework, Art. 8). For the United
     * Kingdom it is the United Kingdom, and from Northern Ireland the Union as well:
     * HMRC treats a margin sale from there to a member state as one within it.
     */
    private function insideMarginTerritory(Jurisdiction $origin, TaxQuery $query): bool
    {
        $destination = $query->place;

        if ($destination->country->equals($origin->country)) {
            return ! $this->outsideVatArea($query);
        }

        if ($origin->taxProfile->isEuMember) {
            return $destination->taxProfile->isEuMember
                ? ! $this->outsideVatArea($query)
                : $this->isNorthernIreland($destination, $query->postalCode);
        }

        return $this->isNorthernIreland($origin, null) && $destination->taxProfile->isEuMember && ! $this->outsideVatArea($query);
    }

    /** The Canaries, Ceuta, Åland and the rest: inside a member state, outside its VAT. */
    private function outsideVatArea(TaxQuery $query): bool
    {
        $place = $query->place;
        $territory = $this->territories?->for($place->country, $query->postalCode, $query->on());

        if ($territory === null && $place->subdivision !== null && $this->territories instanceof EuTerritoriesBySubdivision) {
            $territory = $this->territories->forSubdivision($place->subdivision, $query->on());
        }

        return $territory !== null && ($territory->outsideVatArea || $territory->collectedUnder !== null);
    }

    private function isNorthernIreland(Jurisdiction $place, ?string $postalCode): bool
    {
        return $place->country->value === 'GB'
            && ($place->subdivision?->value === 'GB-NIR' || preg_match('/^BT\d/', strtoupper(trim($postalCode ?? ''))) === 1);
    }

    private function nothing(Money $like): Money
    {
        return Money::zero($like->getCurrency(), $like->getContext());
    }
}
