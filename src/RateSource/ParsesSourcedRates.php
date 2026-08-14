<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Tax\Exceptions\ImplausibleTaxRate;
use Cbox\Tax\ValueObjects\TaxRate;

/**
 * Reads a percentage out of sourced data, and refuses anything that cannot be one.
 *
 * The refusal belongs HERE, at the boundary, not at {@see TaxRate}'s constructor.
 * A feed publishing `-25` or `725` — a poisoned mirror, or a schema change that
 * moved a decimal point — is missing data as far as the engine is concerned, and
 * missing data returns null so a composed {@see ChainTaxRateSource} falls through
 * to the next source. Letting the value reach the constructor instead turns a
 * recoverable gap into an {@see ImplausibleTaxRate} thrown from inside a rate
 * source, which no caller is positioned to handle.
 *
 * The constructor guard stays as the last line of defence: past this point an
 * out-of-range rate really is a programming error, which is what it raises.
 */
trait ParsesSourcedRates
{
    /**
     * A numeric value as a percentage string, or null when it is absent,
     * non-numeric, or outside the 0–100 a percentage can occupy.
     */
    private function number(mixed $value): ?string
    {
        $numeric = match (true) {
            is_int($value), is_float($value) => (string) $value,
            is_string($value) && is_numeric($value) => $value,
            default => null,
        };

        if ($numeric === null) {
            return null;
        }

        $percentage = (float) $numeric;

        return $percentage < 0.0 || $percentage > 100.0 ? null : $numeric;
    }
}
