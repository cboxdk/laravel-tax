<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Caches the current rate resolved by an inner source (e.g. a {@see RemoteRateSource}
 * hitting a live feed on every call). Date-specific lookups (a non-null `$at`)
 * bypass the cache, since a historical rate must not be served from the
 * current-rate cache. A `null` result is not cached — a genuine miss re-queries.
 *
 * The key covers EVERYTHING that can change the answer: country/subdivision, the
 * rooftop LOCALITY, the category, the commodity code, and a namespace identifying
 * the composition. Locality is the one that matters most — without it every
 * rooftop address in a state collapses onto one entry, and a Los Angeles rate is
 * served for a San Francisco address until the entry expires. For a rate cache
 * that is not a stale-data nuisance, it is a wrong invoice.
 *
 * Wraps a commodity-aware inner source without hiding it: see
 * {@see ChainTaxRateSource} for why a wrapper must re-advertise the capability.
 */
readonly class CachingTaxRateSource implements CommodityRateSource
{
    public function __construct(
        private TaxRateSource $inner,
        private Repository $cache,
        private int $ttl = 86400,
        /**
         * Distinguishes this composition's entries from another's in a shared
         * cache. Two differently-composed caching sources over one app cache would
         * otherwise overwrite each other's rates.
         */
        private string $namespace = 'default',
    ) {}

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        return $this->rateForCommodity($jurisdiction, $category, null, $at);
    }

    public function rateForCommodity(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?string $commodityCode,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        // A HISTORICAL rate must neither be served from the current-rate cache nor
        // poison it. Today's is still cached — the test is the date, not merely
        // whether one was supplied. (Every calculator call now carries a date, so
        // testing for non-null here would silently disable the cache entirely and
        // put the live feed back on the hot path.)
        if (! $this->isCurrent($at)) {
            return $this->resolve($jurisdiction, $category, $commodityCode, $at);
        }

        $key = $this->keyFor($jurisdiction, $category, $commodityCode);

        $cached = $this->cache->get($key);

        if ($cached instanceof TaxRate) {
            return $cached;
        }

        $rate = $this->resolve($jurisdiction, $category, $commodityCode, null);

        if ($rate !== null) {
            $this->cache->put($key, $rate, $this->ttl);
        }

        return $rate;
    }

    private function resolve(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?string $commodityCode,
        ?DateTimeImmutable $at,
    ): ?TaxRate {
        return $commodityCode !== null && $this->inner instanceof CommodityRateSource
            ? $this->inner->rateForCommodity($jurisdiction, $category, $commodityCode, $at)
            : $this->inner->rateFor($jurisdiction, $category, $at);
    }

    /** Whether the lookup is for today — null means now, and so does today's date. */
    private function isCurrent(?DateTimeImmutable $at): bool
    {
        return $at === null || $at->format('Y-m-d') === new DateTimeImmutable()->format('Y-m-d');
    }

    private function keyFor(Jurisdiction $jurisdiction, TaxClass $category, ?string $commodityCode): string
    {
        $where = $jurisdiction->subdivision !== null
            ? $jurisdiction->subdivision->value
            : $jurisdiction->country->value;

        $locality = $jurisdiction->locality !== null ? (string) $jurisdiction->locality : '';

        return implode(':', [
            'cbox-tax:rate',
            $this->namespace,
            $where,
            $locality,
            $category->value,
            $commodityCode ?? '',
        ]);
    }
}
