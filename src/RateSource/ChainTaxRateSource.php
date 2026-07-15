<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * Tries each source in order and returns the first rate found — e.g. an
 * authoritative feed first, a static fallback last. Returns `null` only when every
 * source has no rate.
 */
readonly class ChainTaxRateSource implements TaxRateSource
{
    /**
     * @param  list<TaxRateSource>  $sources
     */
    public function __construct(private array $sources) {}

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxCategory $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        foreach ($this->sources as $source) {
            $rate = $source->rateFor($jurisdiction, $category, $at);

            if ($rate !== null) {
                return $rate;
            }
        }

        return null;
    }
}
