<?php

declare(strict_types=1);

namespace Cbox\Tax\Taxability;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Enums\TaxCategory;

/**
 * A taxability matrix backed by a static override map. Everything is taxable by
 * default; overrides mark specific jurisdiction/category combinations as exempt.
 * This is a safe, predictable default — production should bind a matrix sourced
 * from an authoritative feed (e.g. the SST taxability matrices) for per-state SaaS
 * taxability, which is DATA that changes.
 */
readonly class StaticProductTaxability implements ProductTaxability
{
    /**
     * @param  array<string, bool>  $overrides  Key "<jurisdiction>:<category>" => taxable,
     *                                          e.g. "US-CA:digital_service" => false.
     */
    public function __construct(private array $overrides = []) {}

    public function isTaxable(Jurisdiction $jurisdiction, TaxCategory $category): bool
    {
        $where = $jurisdiction->subdivision !== null
            ? $jurisdiction->subdivision->value
            : $jurisdiction->country->value;

        return $this->overrides[$where.':'.$category->value] ?? true;
    }
}
