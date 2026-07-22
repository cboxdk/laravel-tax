<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\TaxCategory;
use RuntimeException;

/**
 * Raised when the product taxability matrix cannot make a jurisdiction/category
 * decision. Deny-by-default: unknown taxability blocks, it does not silently
 * become taxable or exempt.
 */
class UnresolvedProductTaxability extends RuntimeException
{
    public static function for(Jurisdiction $jurisdiction, TaxCategory $category): self
    {
        $where = $jurisdiction->subdivision !== null
            ? $jurisdiction->subdivision->value
            : $jurisdiction->country->value;

        return new self(sprintf(
            'No product taxability available for "%s" in "%s". Refusing to assess rather than guess.',
            $category->value,
            $where,
        ));
    }
}
