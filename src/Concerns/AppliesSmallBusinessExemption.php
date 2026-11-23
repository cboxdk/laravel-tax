<?php

declare(strict_types=1);

namespace Cbox\Tax\Concerns;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\RefusalReason;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\ThresholdCurrencyMismatch;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\ValueObjects\SmallBusinessExemption;
use Cbox\Tax\ValueObjects\SmallBusinessRule;
use Cbox\Tax\ValueObjects\SmallBusinessThreshold;
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

        foreach ([1 => $claim->previousYearTurnover, 2 => $claim->twoYearsAgoTurnover] as $back => $then) {
            $ceiling = $this->currentCeiling($rule);

            if ($back <= $rule->excludesFollowingYears && $ceiling !== null && $then !== null
                && $then->getCurrency()->getCurrencyCode() === $rule->currency && $then->isGreaterThan(Money::of($ceiling, $rule->currency))) {
                return $assessment->with(reason: $assessment->reason.sprintf(
                    ' The seller\'s small-business exemption in %1$s does not cover this supply: its turnover there %2$s, %3$s %4$s, passed %3$s %5$s, which shuts it out for %6$s after%7$s.',
                    $country,
                    $back === 1 ? 'in the previous calendar year' : 'two calendar years ago',
                    $rule->currency,
                    $then->getAmount(),
                    $ceiling,
                    $rule->excludesFollowingYears === 1 ? 'the year' : 'two years',
                    $cited,
                ));
            }
        }

        // The whole sale, where this is one line of it: the exemption ends with the
        // sale that passes a limit, and a refund pushes nothing over it.
        $sale = $this->inThresholdCurrency($query, ($query->saleAmount ?? $query->amount), $rule);
        $sale = $sale->isNegative() ? Money::zero($rule->currency) : $sale;
        $out = static fn (string $why): TaxAssessment => $assessment->with(reason: $assessment->reason.sprintf(' The seller\'s small-business exemption in %s does not cover this supply: %s%s.', $country, $why, $cited));

        $excess = null;
        $kept = '';
        $after = null;

        $named = [];

        foreach ($this->limitsFor($claim, $rule, $country) as $limit) {
            $amount = Money::of($limit->amount, $rule->currency);
            $named[] = sprintf('%s %s %s%s', $rule->currency, $limit->amount, match ($limit->measuredOver) {
                SmallBusinessThreshold::PREVIOUS_CALENDAR_YEAR => 'in the previous calendar year',
                SmallBusinessThreshold::ROLLING_12_MONTHS => 'over twelve months',
                default => 'this calendar year',
            }, $limit->appliesTo === 'all' ? '' : sprintf(' (%s)', str_replace('_', ' ', $limit->appliesTo)));

            if ($limit->measuredOver === SmallBusinessThreshold::PREVIOUS_CALENDAR_YEAR) {
                $previous = $this->turnoverOf($claim->previousYearTurnover, $limit, $claim->previousYearServicesTurnover, $claim->previousYearFoodAndLodgingTurnover, $rule, $country, 'previous calendar year');

                if ($previous->isGreaterThan($amount)) {
                    return $out(sprintf('its turnover there in the previous calendar year, %1$s %2$s, passed the limit of %1$s %3$s', $rule->currency, $previous->getAmount(), $limit->amount));
                }

                continue;
            }

            $before = $limit->measuredOver === SmallBusinessThreshold::ROLLING_12_MONTHS
                ? $this->turnoverOf($claim->rollingTwelveMonthsTurnover, $limit, null, null, $rule, $country, 'twelve months before the supply')
                : $this->turnoverOf($claim->turnover, $limit, $claim->servicesTurnover, $claim->foodAndLodgingTurnover, $rule, $country, 'calendar year');
            $ceiling = Money::of($limit->ceiling(), $rule->currency);
            $with = $before->plus($sale);
            $after = $after === null || $with->isGreaterThan($after) ? $with : $after;
            $window = $limit->measuredOver === SmallBusinessThreshold::ROLLING_12_MONTHS ? 'over the twelve months before this sale' : 'this calendar year';

            if ($before->isGreaterThan($ceiling)) {
                return $out(sprintf('its turnover there %1$s, %2$s %3$s before this sale, has passed %2$s %4$s', $window, $rule->currency, $before->getAmount(), $limit->ceiling()));
            }

            if ($with->isGreaterThan($ceiling)) {
                if ($rule->crossing === SmallBusinessRule::CROSSING_SUPPLY) {
                    return $out(sprintf('its turnover there %1$s, %2$s %3$s before this sale and %4$s with it, passes %2$s %5$s, and the exemption ends with the supply that passes it', $window, $rule->currency, $before->getAmount(), $with->getAmount(), $limit->ceiling()));
                }

                if ($rule->crossing === SmallBusinessRule::EXCESS_ONLY) {
                    $over = $with->minus($ceiling);
                    $excess = $excess === null || $over->isGreaterThan($excess) ? $over : $excess;

                    continue;
                }

                $kept = sprintf(' This sale takes the turnover past %1$s %2$s; in %3$s the supply that passes it is still exempt, and tax starts with the next one.', $rule->currency, $limit->ceiling(), $country);

                continue;
            }

            if ($limit->tolerance !== null && $with->isGreaterThan($amount)) {
                $kept = sprintf(' The turnover has passed %1$s %2$s but not the tolerance of %1$s %3$s, which keeps the exemption to the end of the calendar year.', $rule->currency, $limit->amount, $limit->tolerance);
            }
        }

        if ($excess !== null && $sale->isPositive()) {
            return $this->taxOnlyTheExcess($assessment, $rule, $country, $cited, $sale, $excess);
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
                'Small-business exemption in %1$s%2$s: the seller charges no VAT and deducts none. Its turnover there is %3$s %4$s with this sale, within %7$s.%5$s%6$s The invoice states no VAT and says the exemption applies.',
                $country,
                $cited,
                $rule->currency,
                $after?->getAmount() ?? '0.00',
                $kept,
                $assessment->treatment === TaxTreatment::IntraCommunitySupply
                    ? ' Goods sent to a business in another member state by an exempt small business are not an exempt intra-Community supply (Art. 139(1) of Council Directive 2006/112/EC): the customer accounts for no acquisition on this invoice.'
                    : '',
                count($named) === 1 ? 'its limit of '.$named[0] : 'its limits of '.implode(' and ', $named),
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
        // Goods that leave the state they are dispatched from untaxed are supplied
        // there: Art. 138 would exempt the dispatch where it begins, and so does an
        // export, to a third country or to a territory outside the VAT area (the
        // Canary Islands), which a regime answers in the destination's name.
        $leavesUntaxed = $assessment->treatment === TaxTreatment::IntraCommunitySupply
            || ($assessment->treatment === TaxTreatment::ZeroRated && $assessment->rate === null);

        if ($leavesUntaxed) {
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

        if (! $rule->available) {
            throw new UnresolvedTaxRule(sprintf(
                '%1$s has no small-business exemption (%2$s)%3$s, so a seller cannot claim one there. Remove the small_business registration in %1$s.',
                $country,
                $rule->citation ?? 'its VAT law',
                $rule->unavailableBecause === null ? '' : ': "'.$rule->unavailableBecause.'"',
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

    /**
     * The limits that bind this seller: every `all` and `services` limit, the
     * first-year one only in the first year, a sector's only for that sector
     * and in place of the general one, and the goods-share variants (Ireland)
     * by the share the host states.
     *
     * @return list<SmallBusinessThreshold>
     */
    private function limitsFor(SmallBusinessExemption $claim, SmallBusinessRule $rule, string $country): array
    {
        $limits = [];

        foreach ($rule->thresholds as $limit) {
            if ($limit->firstYearOfActivity && ! $claim->firstYearOfActivity) {
                continue;
            }

            if (! in_array($limit->appliesTo, ['all', SmallBusinessThreshold::SERVICES, SmallBusinessThreshold::SERVICES_OTHER_THAN_FOOD_AND_LODGING], true) && $limit->appliesTo !== $claim->sector) {
                continue;
            }

            if ($limit->goodsShareAtLeast !== null || $limit->goodsShareBelow !== null) {
                $share = $this->goodsShare($claim, $country);

                if (($limit->goodsShareAtLeast !== null && $share < (float) $limit->goodsShareAtLeast)
                    || ($limit->goodsShareBelow !== null && $share >= (float) $limit->goodsShareBelow)) {
                    continue;
                }
            }

            $limits[] = $limit;
        }

        // A sector's own limit replaces the general one rather than adding to it: Denmark's
        // DKK 350,000 for an artist's first sales is not also held to the DKK 50,000.
        $replaced = [];

        foreach ($limits as $limit) {
            if ($limit->appliesTo === $claim->sector) {
                $replaced[] = $limit->measuredOver;
            }
        }

        return array_values(array_filter(
            $limits,
            fn (SmallBusinessThreshold $limit): bool => $limit->appliesTo !== 'all' || ! in_array($limit->measuredOver, $replaced, true),
        ));
    }

    /** Goods as a percentage of this year's turnover there; a state that needs it refuses without it. */
    private function goodsShare(SmallBusinessExemption $claim, string $country): float
    {
        if ($claim->servicesTurnover === null) {
            throw new UnresolvedTaxRule(sprintf(
                'The small-business limit in %s depends on how much of the seller\'s turnover there is goods, and the seller\'s turnover in services was not given.',
                $country,
            ), RefusalReason::SmallBusinessExemptionUnavailable);
        }

        $total = $claim->turnover->getAmount()->toFloat();

        return $total <= 0.0 ? 100.0 : 100.0 * ($total - $claim->servicesTurnover->getAmount()->toFloat()) / $total;
    }

    /**
     * The turnover a limit is measured against, in the rule's currency. A services
     * limit reads the services part, and France's reads it without food served on the
     * premises and lodging; a figure the limit needs and the host did not give is a
     * refusal, never zero.
     */
    private function turnoverOf(?Money $total, SmallBusinessThreshold $limit, ?Money $services, ?Money $foodAndLodging, SmallBusinessRule $rule, string $country, string $window): Money
    {
        $figure = match ($limit->appliesTo) {
            SmallBusinessThreshold::SERVICES => $services,
            SmallBusinessThreshold::SERVICES_OTHER_THAN_FOOD_AND_LODGING => $services === null || $foodAndLodging === null ? null : $services->minus($foodAndLodging),
            default => $total,
        };

        if ($figure === null) {
            throw new UnresolvedTaxRule(sprintf(
                'The small-business exemption in %s is measured on the seller\'s %sturnover there over the %s, and that figure was not given. It is not assumed to be zero.',
                $country,
                match ($limit->appliesTo) {
                    SmallBusinessThreshold::SERVICES => 'services ',
                    SmallBusinessThreshold::SERVICES_OTHER_THAN_FOOD_AND_LODGING => 'services (other than food served on the premises and lodging) ',
                    default => '',
                },
                $window,
            ), RefusalReason::SmallBusinessExemptionUnavailable);
        }

        if ($figure->getCurrency()->getCurrencyCode() !== $rule->currency) {
            throw ThresholdCurrencyMismatch::between($rule->currency, $figure->getCurrency()->getCurrencyCode());
        }

        return $figure;
    }

    /** The ceiling on this year's turnover, for the years-after exclusion. */
    private function currentCeiling(SmallBusinessRule $rule): ?string
    {
        foreach ($rule->thresholds as $limit) {
            if ($limit->measuredOver !== SmallBusinessThreshold::PREVIOUS_CALENDAR_YEAR && $limit->appliesTo === 'all' && ! $limit->firstYearOfActivity) {
                return $limit->ceiling();
            }
        }

        return $rule->threshold();
    }

    /**
     * Latvia's crossing: only the part of the supply above the ceiling is taxed
     * (PVN likums art. 34(10)). The ordinary answer's tax, scaled to that share.
     */
    private function taxOnlyTheExcess(TaxAssessment $assessment, SmallBusinessRule $rule, string $country, string $cited, Money $sale, Money $excess): TaxAssessment
    {
        $share = $excess->isGreaterThan($sale) ? '1' : (string) $excess->getAmount()->dividedBy($sale->getAmount(), 10, RoundingMode::HalfUp);
        $tax = $assessment->tax->multipliedBy($share, RoundingMode::HalfUp);

        return $assessment->with(
            net: $assessment->gross->minus($tax),
            tax: $tax,
            reason: $assessment->reason.sprintf(
                ' Small-business exemption in %1$s%2$s: this sale takes the seller past its limit there by %3$s %4$s, and only that part of it is taxed; the rest stays exempt.',
                $country,
                $cited,
                $rule->currency,
                $excess->getAmount(),
            ),
        );
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
