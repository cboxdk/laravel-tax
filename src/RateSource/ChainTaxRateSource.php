<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Exceptions\RateSourceUnavailable;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * Tries each source in order and returns the first rate found — e.g. an
 * authoritative feed first, a static fallback last. Returns `null` only when every
 * source has no rate.
 *
 * A source that FAILS is treated differently from one that simply has nothing.
 * Falling through both ways is how a timed-out feed ended up billing from the
 * static snapshot at the same apparent quality as a live answer. A
 * {@see RateSourceUnavailable} now degrades whatever a later source returns, and
 * if nothing answers at all it is rethrown rather than flattened into `null` —
 * "we could not find out" must not reach the caller as "there is no rate here".
 *
 * It implements {@see CommodityRateSource} so a commodity code SURVIVES the chain.
 * This is not optional politeness: {@see ResolvesRates} decides whether to pass the
 * code by testing the OUTERMOST source, and the service provider composes a chain
 * whenever any live source is enabled — which is the default. A chain that
 * advertised only {@see TaxRateSource} would therefore make
 * every commodity-aware source underneath it unreachable through the calculator,
 * silently, while its own tests kept passing.
 *
 * Sources that cannot use a code are called exactly as before, so composing a
 * commodity-aware source with a static table works.
 */
readonly class ChainTaxRateSource implements CommodityRateSource
{
    /**
     * @param  list<TaxRateSource>  $sources
     */
    public function __construct(private array $sources) {}

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
        $unavailable = null;

        foreach ($this->sources as $source) {
            try {
                $rate = $commodityCode !== null && $source instanceof CommodityRateSource
                    ? $source->rateForCommodity($jurisdiction, $category, $commodityCode, $at)
                    : $source->rateFor($jurisdiction, $category, $at);
            } catch (RateSourceUnavailable $e) {
                // Not an answer — a fault. Keep going, because a later source may
                // still know the rate, but remember that the preferred one was
                // broken so whatever we end up with cannot pass for a clean result.
                $unavailable ??= $e;

                continue;
            }

            if ($rate !== null) {
                return $unavailable === null ? $rate : $rate->degraded($unavailable->why);
            }
        }

        // Nothing answered AND something was broken. Returning null here would be
        // the original bug in its purest form: the caller reads "no rate for this
        // jurisdiction", which is a statement about the world, when the truth is
        // "we could not find out", which is a statement about us.
        if ($unavailable !== null) {
            throw $unavailable;
        }

        return null;
    }
}
