<?php

declare(strict_types=1);

namespace Cbox\Tax\Returns;

use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\ValueObjects\ReturnLine;
use Cbox\Tax\ValueObjects\TaxReturn;

/**
 * Groups assessments by taxing jurisdiction (country + subdivision) + currency and
 * sums their net and tax. Sub-federal supplies (US states, Canadian provinces) land
 * on their own per-subdivision line, and each EU Member State keeps its own line —
 * so the return can drive a per-jurisdiction filing instead of collapsing a country.
 * Money of different currencies is never mixed — each currency is its own line, and
 * summing uses exact `Money::plus`, so no rounding remainder is introduced.
 */
readonly class DefaultReturnAggregator implements ReturnAggregator
{
    public function aggregate(iterable $assessments): TaxReturn
    {
        /** @var array<string, ReturnLine> $lines */
        $lines = [];

        foreach ($assessments as $assessment) {
            $place = $assessment->placeOfSupply;
            $country = $place->country;
            $subdivision = $place->subdivision;
            $currency = $assessment->net->getCurrency()->getCurrencyCode();
            $subdivisionKey = $subdivision !== null ? $subdivision->value : '';
            $key = $country->value.'|'.$subdivisionKey.'|'.$currency;

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

        return new TaxReturn(array_values($lines));
    }
}
