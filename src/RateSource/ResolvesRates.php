<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;

/**
 * Asks a rate source for the query's rate, passing the supply's commodity code
 * when the source can use one.
 *
 * The branch lives here rather than in each regime so the five of them cannot
 * drift: a source that does not implement {@see CommodityRateSource} is called
 * exactly as before, and a query without a code reaches both kinds identically.
 */
trait ResolvesRates
{
    protected function resolveRate(TaxRateSource $rates, TaxQuery $query, ?Jurisdiction $place = null): ?TaxRate
    {
        $where = $place ?? $query->place;

        return $rates instanceof CommodityRateSource
            ? $rates->rateForCommodity($where, $query->category, $query->commodityCode)
            : $rates->rateFor($where, $query->category);
    }
}
