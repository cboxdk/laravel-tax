<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\RefusalReason;
use RuntimeException;

/**
 * Raised when a rate source cannot supply a rate for a jurisdiction the engine
 * expected to tax. Deny-by-default: a missing rate blocks, it does not silently
 * become 0%.
 */
class UnresolvedTaxRate extends RuntimeException implements Refusal
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

    /**
     * The supply cannot be priced under the seller's asserted remote-seller
     * election — the state has no such scheme, the dataset is too old to carry
     * the section, an annual determination has lapsed, or the category carries
     * a special rate the flat figure must not paper over. Refusing beats both
     * flattening what the state prices specially and silently pricing as if
     * the seller had never elected.
     */
    public static function underElection(SubdivisionCode $subdivision, string $program): self
    {
        return new self(sprintf(
            'Cannot price a %s supply under the elected %s. Refusing to assess rather than guess around the election.',
            $subdivision->value,
            $program,
        ));
    }

    public function reason(): RefusalReason
    {
        return RefusalReason::RateUnavailable;
    }
}
