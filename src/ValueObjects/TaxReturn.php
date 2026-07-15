<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Geo\ValueObjects\CountryCode;

/**
 * Return-data aggregated from a set of assessments: one {@see ReturnLine} per
 * jurisdiction + currency. This is what the engine produces for filing; it owns
 * the aggregation, submission to each authority is the host's concern.
 */
readonly class TaxReturn
{
    /**
     * @param  list<ReturnLine>  $lines
     */
    public function __construct(public array $lines) {}

    public function lineFor(CountryCode $country, string $currency): ?ReturnLine
    {
        foreach ($this->lines as $line) {
            if ($line->country->equals($country) && $line->currency === $currency) {
                return $line;
            }
        }

        return null;
    }
}
