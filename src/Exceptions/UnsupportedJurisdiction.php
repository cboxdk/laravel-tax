<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Cbox\Geo\ValueObjects\CountryCode;
use RuntimeException;

/**
 * Raised when no tax regime is modelled for a jurisdiction. Deny-by-default: the
 * engine refuses rather than assuming a supply is tax-free.
 */
class UnsupportedJurisdiction extends RuntimeException
{
    public static function for(CountryCode $country): self
    {
        return new self(sprintf(
            'No tax regime is modelled for jurisdiction "%s". Refusing to assess rather than assume tax-free.',
            $country->value,
        ));
    }
}
