<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\TaxClass;
use RuntimeException;

/**
 * Raised when the product taxability matrix cannot make a jurisdiction/category
 * decision. Deny-by-default: unknown taxability blocks, it does not silently
 * become taxable or exempt.
 */
class UnresolvedProductTaxability extends RuntimeException
{
    public static function for(Jurisdiction $jurisdiction, TaxClass $category): self
    {
        $where = $jurisdiction->subdivision !== null
            ? $jurisdiction->subdivision->value
            : $jurisdiction->country->value;

        return new self(sprintf(
            'No product taxability available for "%s" in "%s". Refusing to assess rather than guess. '
            .'Either enable the us-tax-data dataset (tax.us_tax_data.enabled), which carries a determination '
            .'for this pair unless its sources disagreed, or bind a ProductTaxability with an explicit '
            .'"%s:%s" override.',
            $category->value,
            $where,
            $where,
            $category->value,
        ));
    }

    /**
     * The jurisdiction's rule for this category is CONDITIONAL on something the
     * boolean seam cannot see — typically a per-item price threshold. The answer
     * exists; it just cannot be given from (jurisdiction, category) alone, so the
     * engine refuses rather than charging the full rate on a supply that may be
     * exempt.
     */
    public static function conditional(Jurisdiction $jurisdiction, TaxClass $category): self
    {
        $where = $jurisdiction->subdivision !== null
            ? $jurisdiction->subdivision->value
            : $jurisdiction->country->value;

        return new self(sprintf(
            'Taxability of "%s" in "%s" is conditional (e.g. a per-item price threshold) and cannot be decided from the category alone. Supply a taxability matrix that resolves the condition, or exclude the category.',
            $category->value,
            $where,
        ));
    }
}
