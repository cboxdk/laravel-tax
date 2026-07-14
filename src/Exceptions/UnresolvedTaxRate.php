<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Cbox\Geo\ValueObjects\Jurisdiction;
use RuntimeException;

/**
 * Raised when a rate source cannot supply a rate for a jurisdiction the engine
 * expected to tax. Deny-by-default: a missing rate blocks, it does not silently
 * become 0%.
 */
class UnresolvedTaxRate extends RuntimeException
{
    public static function for(Jurisdiction $jurisdiction): self
    {
        $where = $jurisdiction->subdivision !== null
            ? $jurisdiction->subdivision->value
            : $jurisdiction->country->value;

        return new self(sprintf(
            'No tax rate available for "%s". Refusing to assess rather than assume 0%%.',
            $where,
        ));
    }
}
