<?php

declare(strict_types=1);

namespace Cbox\Tax\Catalogue;

use Cbox\Tax\Contracts\ProductCatalogue;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\ValueObjects\ProductTaxMapping;

/**
 * A catalogue held in memory, keyed by item code.
 *
 * For a small fixed catalogue — a SaaS with nine plans, a shop with fifty SKUs —
 * and for tests. Anything larger belongs behind a query against your own product
 * table, which is what the contract is for; this ships so the seam is usable on day
 * one rather than being a contract with nothing behind it.
 *
 * Codes match exactly. Case and whitespace are NOT normalised, deliberately: a SKU
 * is an identifier in your system, and quietly treating `SKU-1` and `sku-1` as the
 * same thing would be this package inventing a rule about your data.
 */
readonly class ArrayProductCatalogue implements ProductCatalogue
{
    /** @var array<string, ProductTaxMapping> */
    private array $mappings;

    /**
     * @param  array<string, ProductTaxMapping|TaxClass>  $mappings  item code =>
     *                                                               how it is taxed. A bare {@see TaxClass} is accepted for the common
     *                                                               case of a product with no commodity code, so a small catalogue reads
     *                                                               as a list of decisions rather than a wall of constructors.
     */
    public function __construct(array $mappings = [])
    {
        $normalized = [];

        foreach ($mappings as $code => $mapping) {
            $normalized[(string) $code] = $mapping instanceof TaxClass
                ? new ProductTaxMapping($mapping)
                : $mapping;
        }

        $this->mappings = $normalized;
    }

    public function find(string $itemCode): ?ProductTaxMapping
    {
        return $this->mappings[$itemCode] ?? null;
    }

    /**
     * The item codes this catalogue carries, for a review that wants to diff them
     * against the product table they came from.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->mappings);
    }
}
