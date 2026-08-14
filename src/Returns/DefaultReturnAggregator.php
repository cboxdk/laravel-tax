<?php

declare(strict_types=1);

namespace Cbox\Tax\Returns;

use Brick\Money\Money;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\ValueObjects\AuthorityTotal;
use Cbox\Tax\ValueObjects\ReturnLine;
use Cbox\Tax\ValueObjects\ReturnPeriod;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxReturn;

/**
 * Groups assessments by taxing jurisdiction (country + subdivision) + currency and
 * sums their net and tax. Sub-federal supplies (US states, Canadian provinces) land
 * on their own per-subdivision line, and each EU Member State keeps its own line —
 * so the return can drive a per-jurisdiction filing instead of collapsing a country.
 * Money of different currencies is never mixed — each currency is its own line, and
 * summing uses exact `Money::plus`, so no rounding remainder is introduced.
 *
 * Each line also carries the period's per-AUTHORITY totals where they can be known.
 * A jurisdiction total is not what gets remitted in a stacked state: Missouri is
 * paid as a state figure, a county figure and a city figure, and a return that only
 * reported "Missouri: $412.87" left the operator to rebuild the split by hand from
 * the individual assessments — which is the one place the arithmetic must not be
 * redone.
 */
readonly class DefaultReturnAggregator implements ReturnAggregator
{
    public function aggregate(iterable $assessments, ?ReturnPeriod $period = null): TaxReturn
    {
        /** @var array<string, list<TaxAssessment>> $grouped */
        $grouped = [];
        /** @var array<string, ReturnLine> $lines */
        $lines = [];

        foreach ($assessments as $assessment) {
            // Outside the period being filed. An assessment with no reporting date
            // is EXCLUDED rather than assumed in: a supply that cannot say which
            // period it belongs to must not silently land in the one being filed.
            if ($period !== null
                && ($assessment->reportedOn === null || ! $period->covers($assessment->reportedOn))) {
                continue;
            }

            $place = $assessment->placeOfSupply;
            $country = $place->country;
            $subdivision = $place->subdivision;
            $currency = $assessment->net->getCurrency()->getCurrencyCode();
            $subdivisionKey = $subdivision !== null ? $subdivision->value : '';
            $key = $country->value.'|'.$subdivisionKey.'|'.$currency;

            $grouped[$key][] = $assessment;

            if (isset($lines[$key])) {
                $existing = $lines[$key];
                $lines[$key] = new ReturnLine(
                    $country,
                    $subdivision,
                    $currency,
                    $existing->net->plus($assessment->net),
                    $existing->tax->plus($assessment->tax),
                    $existing->count + 1,
                );
            } else {
                $lines[$key] = new ReturnLine($country, $subdivision, $currency, $assessment->net, $assessment->tax, 1);
            }
        }

        $complete = [];

        foreach ($lines as $key => $line) {
            $complete[] = new ReturnLine(
                $line->country,
                $line->subdivision,
                $line->currency,
                $line->net,
                $line->tax,
                $line->count,
                $this->authorities($grouped[$key]),
            );
        }

        return new TaxReturn($complete, $period);
    }

    /**
     * Roll one jurisdiction's assessments up per taxing authority.
     *
     * Null on the first thing that makes the split unfileable, because a partial
     * roll-up is worse than none here: the remaining figures still look like a
     * complete return, and the omission is invisible precisely because what is left
     * is plausible. Someone signs this.
     *
     * @param  list<TaxAssessment>  $assessments
     * @return list<AuthorityTotal>|null
     */
    private function authorities(array $assessments): ?array
    {
        /** @var array<string, AuthorityTotal> $totals */
        $totals = [];

        foreach ($assessments as $assessment) {
            // A supply that charged nothing has nothing to attribute, and no
            // missing breakdown to complain about.
            if ($assessment->tax->isZero()) {
                continue;
            }

            // An EMPTY breakdown on a taxed supply is not a split, it is the
            // absence of one — the same distinction TaxRate draws for components.
            if ($assessment->breakdown === null || $assessment->breakdown->isEmpty()) {
                return null;
            }

            foreach ($assessment->breakdown->lines as $share) {
                // A document breakdown can afford an authority it cannot name — it
                // is there to be read. A return cannot: this figure is remitted to
                // somebody, and "some unnamed special district" is not an address.
                // Merging two of them would also report one district owed both
                // shares.
                $identity = $share->code ?? ($share->name !== null ? 'name:'.$share->name : null);

                if ($identity === null) {
                    return null;
                }

                $mergeKey = $share->level->value.'|'.$identity;
                $existing = $totals[$mergeKey] ?? null;

                $totals[$mergeKey] = new AuthorityTotal(
                    $share->level,
                    $existing === null ? $share->tax : $existing->tax->plus($share->tax),
                    $share->code,
                    $share->name,
                );
            }
        }

        return $totals === [] ? null : array_values($totals);
    }
}
