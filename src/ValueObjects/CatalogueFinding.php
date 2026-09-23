<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Geo\ValueObjects\Jurisdiction;

/**
 * One product whose answer in one market depends on something the catalogue has not
 * said about it — or a product the catalogue does not know at all.
 */
final readonly class CatalogueFinding
{
    /**
     * @param  list<UnsettledCondition>  $conditions
     */
    public function __construct(
        public string $itemCode,
        public ?Jurisdiction $market,
        public array $conditions = [],
    ) {}

    public static function unmapped(string $itemCode): self
    {
        return new self($itemCode, null);
    }

    /** Not in the catalogue: priced at the fallback class everywhere it is sold. */
    public function isUnmapped(): bool
    {
        return $this->market === null;
    }

    /** Whether adding the product's CN or HS code would settle any of it. */
    public function settleableByCommodityCode(): bool
    {
        return array_any($this->conditions, static fn (UnsettledCondition $c): bool => $c->settledByCommodityCode);
    }

    /**
     * The register's facts that would settle the rest, once each, for the product form
     * to ask about.
     *
     * @return list<string>
     */
    public function factsNeeded(): array
    {
        $facts = array_merge(...array_map(static fn (UnsettledCondition $c): array => $c->facts, $this->conditions));
        $facts = array_values(array_unique($facts));
        sort($facts);

        return $facts;
    }
}
