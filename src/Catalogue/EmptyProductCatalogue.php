<?php

declare(strict_types=1);

namespace Cbox\Tax\Catalogue;

use Cbox\Tax\Contracts\ProductCatalogue;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\ValueObjects\ProductTaxMapping;

/**
 * The bound-by-default catalogue: it knows no item codes.
 *
 * A null object rather than a nullable dependency, so the calculator has one code
 * path and the seam is exercised by every test that runs without a host catalogue.
 *
 * Knowing nothing is the correct default, not a placeholder. This package ships no
 * opinion about anybody's SKUs, and an app that never sets an item code behaves
 * exactly as it did before the catalogue existed. An app that DOES set one and
 * binds nothing gets its lines flagged {@see RateLimit::ItemUnmapped},
 * which is the honest report: the code was given, nothing could answer for it, and
 * the fallback class was used.
 */
readonly class EmptyProductCatalogue implements ProductCatalogue
{
    public function find(string $itemCode): ?ProductTaxMapping
    {
        return null;
    }
}
