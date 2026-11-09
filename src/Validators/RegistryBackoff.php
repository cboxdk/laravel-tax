<?php

declare(strict_types=1);

namespace Cbox\Tax\Validators;

use Cbox\Geo\ValueObjects\CountryCode;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Remembers, for a short while, that a tax-ID registry could not be reached for a
 * country, so the next lookups answer *inconclusive* at once instead of each waiting
 * out the full timeout against a registry that is down.
 *
 * Only an outage trips it — a timeout, a refused connection, a 5xx or a 429. A
 * registry that answered (valid, invalid, or a body it could not vouch for) never
 * does. The key is per registry AND per country because VIES fails one member state
 * at a time: Germany down says nothing about France.
 *
 * The backoff only ever turns a lookup into "could not verify", which charges tax.
 * It can never make an ID read as valid or as invalid. A cache that itself fails is
 * ignored: the lookup simply goes to the registry, as it would without a backoff.
 *
 * Zero (or fewer) seconds disables it.
 */
class RegistryBackoff
{
    public function __construct(
        private readonly Cache $cache,
        private readonly int $seconds = 60,
    ) {}

    /** Whether the registry is still inside its backoff window for this country. */
    public function active(string $source, CountryCode $country): bool
    {
        if ($this->seconds <= 0) {
            return false;
        }

        try {
            return $this->cache->has($this->key($source, $country));
        } catch (Throwable) {
            return false;
        }
    }

    /** Record that the registry could not be reached for this country. */
    public function trip(string $source, CountryCode $country): void
    {
        if ($this->seconds <= 0) {
            return;
        }

        try {
            $this->cache->put($this->key($source, $country), true, $this->seconds);
        } catch (Throwable) {
            // A cache outage must not turn a registry outage into an exception.
        }
    }

    private function key(string $source, CountryCode $country): string
    {
        return 'cbox-tax:vat-id:unavailable:'.$source.':'.$country->value;
    }
}
