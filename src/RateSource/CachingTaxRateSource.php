<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Caches the current rate resolved by an inner source (e.g. a {@see RemoteRateSource}
 * hitting a live feed on every call). Date-specific lookups (a non-null `$at`)
 * bypass the cache, since a historical rate must not be served from the
 * current-rate cache. A `null` result is not cached — a genuine miss re-queries.
 */
readonly class CachingTaxRateSource implements TaxRateSource
{
    public function __construct(
        private TaxRateSource $inner,
        private Repository $cache,
        private int $ttl = 86400,
    ) {}

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxCategory $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        if ($at !== null) {
            return $this->inner->rateFor($jurisdiction, $category, $at);
        }

        $where = $jurisdiction->subdivision !== null
            ? $jurisdiction->subdivision->value
            : $jurisdiction->country->value;

        $key = 'cbox-tax:rate:'.$where.':'.$category->value;

        $cached = $this->cache->get($key);

        if ($cached instanceof TaxRate) {
            return $cached;
        }

        $rate = $this->inner->rateFor($jurisdiction, $category);

        if ($rate !== null) {
            $this->cache->put($key, $rate, $this->ttl);
        }

        return $rate;
    }
}
