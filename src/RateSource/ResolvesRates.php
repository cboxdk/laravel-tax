<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CategoryKeyedRateSource;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;

/**
 * Asks a rate source for the query's rate — on the supply's date, and passing the
 * commodity code when the source can use one.
 *
 * The branch lives here rather than in each regime so the five of them cannot
 * drift: a source that does not implement {@see CommodityRateSource} is called
 * exactly as before, and a query without a code reaches both kinds identically.
 *
 * The DATE is the reason this trait matters more than it looks. Every source has
 * always accepted one and none was ever given one, so every dated rate window in
 * the package was unreachable through the calculator and a reissued invoice
 * silently repriced at today's rate.
 */
trait ResolvesRates
{
    protected function resolveRate(TaxRateSource $rates, TaxQuery $query, ?Jurisdiction $place = null): ?TaxRate
    {
        $where = $place ?? $query->place;
        $on = $query->on();

        if ($query->categoryKey !== null) {
            // A source that cannot answer a register key must not be asked about the
            // class the key was mapped to: that is a different, broader question, and
            // its answer would be priced as though it were this one.
            if (! $rates instanceof CategoryKeyedRateSource) {
                throw new UnresolvedTaxRule(sprintf(
                    'The bound rate source (%s) cannot answer a register category key, and "%s" was asked for. Bind the register source, or state a TaxClass instead.',
                    $rates::class,
                    $query->categoryKey,
                ));
            }

            return $rates->rateForKey($where, $query->categoryKey, $query->commodityCode, $on);
        }

        return $rates instanceof CommodityRateSource
            ? $rates->rateForCommodity($where, $query->category, $query->commodityCode, $on)
            : $rates->rateFor($where, $query->category, $on);
    }
}
