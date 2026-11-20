<?php

declare(strict_types=1);

namespace Cbox\Tax\Concerns;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\RefusalReason;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\ThresholdCurrencyMismatch;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\ValueObjects\SmallBusinessExemption;
use Cbox\Tax\ValueObjects\SmallBusinessRule;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * The small-business exemption (Arts. 284–288a of the VAT Directive as amended by
 * Directive (EU) 2020/285, in force from 1 January 2025), applied last: to the supply
 * as the ordinary rules placed and taxed it.
 *
 * PER SELLER AND PER MEMBER STATE. A supply is exempt where it is taxed in a state the
 * seller holds the exemption in — its own under that state's threshold, another's with
 * the EX number and a Union turnover within the cap. Everything else keeps its answer:
 * a service a business in another state accounts for itself, a distance sale taxed in
 * a state where the seller holds no exemption.
 *
 * ONE ANSWER IT REPLACES OUTRIGHT: goods to a business in another member state. From
 * an exempt seller that is not an exempt intra-Community supply (Art. 139(1)); it is
 * the same exempt supply at home as any other.
 *
 * IT ENDS WITH THE SUPPLY THAT PASSES THE THRESHOLD. The register holds the figure,
 * the seller's turnover comes from the host, and the supply that takes it over is
 * taxed in full, as every supply after it is.
 *
 * Refused, never taxed quietly at the standard rate: a seller established outside the
 * Union, a state whose scheme the register does not publish, another member state
 * without the EX number.
 */
trait AppliesSmallBusinessExemption
{
    private function applySmallBusinessExemption(TaxQuery $query, TaxAssessment $assessment): TaxAssessment
    {
        if ($query->seller->smallBusiness === []) {
            return $assessment;
        }

        $this->refuseSellerOutsideTheUnion($query);

        $state = $this->stateTaxedIn($query, $assessment);
        $claim = $state === null ? null : $query->seller->smallBusinessIn($state->country, $query->on());

        if ($state === null || $claim === null) {
            return $assessment;
        }

        $rule = $this->smallBusinessRule($query, $claim, $state);
        $country = $state->country->value;
        $cited = $rule->citation === null ? '' : ' ('.$rule->citation.')';

        if (! $query->seller->isEstablishedIn($state->country) && $claim->unionTurnoverExceeded) {
            return $assessment->with(reason: $assessment->reason.sprintf(
                ' The seller\'s small-business exemption in %s does not cover this supply: its Union turnover has passed %s %s in this or the previous calendar year, which ends the exemption in every member state other than its own%s.',
                $country,
                $rule->unionCurrency ?? 'EUR',
                $rule->unionThreshold ?? '100000',
                $cited,
            ));
        }

        $threshold = Money::of($rule->threshold, $rule->currency);
        $before = $claim->turnover;

        if ($before->getCurrency()->getCurrencyCode() !== $rule->currency) {
            throw ThresholdCurrencyMismatch::between($rule->currency, $before->getCurrency()->getCurrencyCode());
        }

        // The whole sale, where this is one line of it: the exemption ends with the
        // sale that passes the threshold, and a refund pushes nothing over it.
        $sale = $this->inThresholdCurrency($query, ($query->saleAmount ?? $query->amount), $rule);
        $after = $sale->isNegative() ? $before : $before->plus($sale);

        if ($after->isGreaterThan($threshold)) {
            return $assessment->with(reason: $assessment->reason.sprintf(
                ' The seller\'s small-business exemption in %1$s does not cover this supply: its turnover there this calendar year, %2$s %3$s before this sale and %4$s with it, passes the threshold of %2$s %5$s, and the exemption ends with the supply that passes it%6$s.',
                $country,
                $rule->currency,
                $before->getAmount(),
                $after->getAmount(),
                $threshold->getAmount(),
                $cited,
            ));
        }

        $zero = Money::zero($query->amount->getCurrency(), $query->amount->getContext());

        return new TaxAssessment(
            treatment: TaxTreatment::Exempt,
            net: $query->amount,
            tax: $zero,
            gross: $query->amount,
            placeOfSupply: $state,
            rate: null,
            reason: sprintf(
                'Small-business exemption in %1$s%2$s: the seller charges no VAT and deducts none. Its turnover there this calendar year is %3$s %4$s with this sale, within the threshold of %3$s %5$s.%6$s The invoice states no VAT and says the exemption applies.',
                $country,
                $cited,
                $rule->currency,
                $after->getAmount(),
                $threshold->getAmount(),
                $assessment->treatment === TaxTreatment::IntraCommunitySupply
                    ? ' Goods sent to a business in another member state by an exempt small business are not an exempt intra-Community supply (Art. 139(1) of Council Directive 2006/112/EC): the customer accounts for no acquisition on this invoice.'
                    : '',
            ),
            taxPoint: $assessment->taxPoint,
            reportedOn: $assessment->reportedOn,
            mentions: $rule->mention === null ? [] : [$rule->mention],
        );
    }

    /**
     * The member state whose tax the supply bears from this seller, or null where the
     * exemption has nothing to act on: a reverse charge is the customer's, and a
     * supply the seller does not collect on is nobody's exemption.
     */
    private function stateTaxedIn(TaxQuery $query, TaxAssessment $assessment): ?Jurisdiction
    {
        if ($assessment->treatment === TaxTreatment::IntraCommunitySupply) {
            // Art. 138 would exempt the dispatch in the state it begins in.
            $from = $query->route->shipFrom;

            return $from !== null && $from->taxProfile->isEuMember ? $from : $this->jurisdictions?->find($query->seller->establishment);
        }

        return in_array($assessment->treatment, [TaxTreatment::Standard, TaxTreatment::MarginScheme, TaxTreatment::ZeroRated, TaxTreatment::Exempt], true)
            ? $assessment->placeOfSupply
            : null;
    }

    /**
     * Wherever the supply ends up taxed: a seller established outside the Union holds
     * the exemption nowhere, so a claim on the date is wrong whatever this sale is.
     */
    private function refuseSellerOutsideTheUnion(TaxQuery $query): void
    {
        $claims = array_filter($query->seller->smallBusiness, static fn (SmallBusinessExemption $claim): bool => $claim->coversDate($query->on()));
        $home = $this->jurisdictions?->find($query->seller->establishment);

        if ($claims === [] || ($home !== null && $home->taxProfile->isEuMember)) {
            return;
        }

        throw new UnresolvedTaxRule(sprintf(
            'The seller claims the small-business exemption in %s and is established in %s. Only a business established in a member state can use it (Art. 284 of Council Directive 2006/112/EC); one established outside the Union, the United Kingdom and Northern Ireland included, cannot.',
            implode(', ', array_map(static fn (SmallBusinessExemption $claim): string => $claim->country->value, array_values($claims))),
            $query->seller->establishment->value,
        ), RefusalReason::SmallBusinessExemptionUnavailable);
    }

    private function smallBusinessRule(TaxQuery $query, SmallBusinessExemption $claim, Jurisdiction $state): SmallBusinessRule
    {
        $country = $state->country->value;

        $rule = $this->smallBusiness?->for($state->country, $query->on());

        if ($rule === null) {
            throw new UnresolvedTaxRule(sprintf(
                'The seller claims the small-business exemption in %s, and the installed register publishes no such exemption there on %s. Its threshold and conditions are not assumed from another state.',
                $country,
                $query->on()->format('Y-m-d'),
            ), RefusalReason::SmallBusinessExemptionUnavailable);
        }

        if ($query->seller->isEstablishedIn($state->country)) {
            return $rule;
        }

        if ($rule->unionThreshold === null) {
            throw new UnresolvedTaxRule(sprintf(
                'The seller is established in %s and claims the small-business exemption in %s, and the installed register does not publish %s\'s conditions for a business established in another member state.',
                $query->seller->establishment->value,
                $country,
                $country,
            ), RefusalReason::SmallBusinessExemptionUnavailable);
        }

        if (! $this->isCrossBorderNumber($claim->identificationNumber)) {
            throw new UnresolvedTaxRule(sprintf(
                'The seller is established in %s and claims the small-business exemption in %s without the identification number its own state issues for the cross-border scheme (suffix EX, Art. 284(3) of Council Directive 2006/112/EC). Without it the exemption does not apply there.',
                $query->seller->establishment->value,
                $country,
            ), RefusalReason::SmallBusinessExemptionUnavailable);
        }

        return $rule;
    }

    private function isCrossBorderNumber(?string $number): bool
    {
        return preg_match('/^[A-Z]{2}[A-Z0-9]+EX$/', strtoupper(preg_replace('/[\s.\-]/', '', $number ?? '') ?? '')) === 1;
    }

    private function inThresholdCurrency(TaxQuery $query, Money $amount, SmallBusinessRule $rule): Money
    {
        $currency = $amount->getCurrency()->getCurrencyCode();

        if ($currency === $rule->currency) {
            return $amount;
        }

        return $this->exchangeRates?->rate($currency, $rule->currency, $query->on())?->convert($amount)
            ?? throw ThresholdCurrencyMismatch::between($rule->currency, $currency);
    }
}
