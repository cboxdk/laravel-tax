<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;

/**
 * Return-data aggregated from a set of assessments: one {@see ReturnLine} per
 * taxing jurisdiction (country + subdivision) + currency. This is what the engine
 * produces for filing; it owns the aggregation, submission to each authority is
 * the host's concern.
 */
readonly class TaxReturn
{
    /**
     * @param  list<ReturnLine>  $lines
     */
    public function __construct(public array $lines) {}

    /**
     * The line for a country + currency, optionally narrowed to a subdivision. When
     * `$subdivision` is null only a national (subdivision-less) line matches — a
     * US state line is not returned for a bare-country lookup.
     */
    public function lineFor(CountryCode $country, string $currency, ?SubdivisionCode $subdivision = null): ?ReturnLine
    {
        foreach ($this->lines as $line) {
            if (! $line->country->equals($country) || $line->currency !== $currency) {
                continue;
            }

            if ($subdivision === null) {
                if ($line->subdivision === null) {
                    return $line;
                }

                continue;
            }

            if ($line->subdivision !== null && $line->subdivision->equals($subdivision)) {
                return $line;
            }
        }

        return null;
    }
}
