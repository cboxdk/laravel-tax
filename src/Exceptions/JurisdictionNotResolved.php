<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\AddressGeocoder;
use RuntimeException;

/**
 * Raised when a sub-federal jurisdiction was not resolved finely enough to assess
 * (e.g. US sales tax with no state, or a rooftop-requiring jurisdiction with only
 * a state). The caller must resolve the address via an {@see AddressGeocoder}
 * first — deny-by-default, never a coarse guess.
 */
class JurisdictionNotResolved extends RuntimeException
{
    public static function needsSubdivision(Jurisdiction $jurisdiction): self
    {
        return new self(sprintf(
            'Tax in %s requires a subdivision (state/province); resolve the address before assessing.',
            $jurisdiction->country->value,
        ));
    }
}
