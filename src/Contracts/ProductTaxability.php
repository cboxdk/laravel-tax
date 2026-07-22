<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\TaxCategory;

/**
 * Decides whether a product category is taxable in a jurisdiction — the
 * taxability-matrix seam. It matters most for US sales tax, where SaaS/digital
 * taxability varies state by state; national VAT/GST regimes generally tax at the
 * standard rate and do not consult it.
 *
 * Sourced DATA, like rates: bind a matrix backed by an authoritative source (e.g.
 * the SST taxability matrices) in production. Implementations may throw when a
 * category/jurisdiction is unknown; callers should treat that as a hard block,
 * not as taxable or exempt.
 */
interface ProductTaxability
{
    public function isTaxable(Jurisdiction $jurisdiction, TaxCategory $category): bool;
}
