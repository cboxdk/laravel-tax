<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Geo\ValueObjects\Jurisdiction;

/**
 * One product whose answer in one market depends on something the catalogue has not
 * said about it — or a product the catalogue does not know at all.
 */
readonly class CatalogueFinding
{
    /**
     * @param  list<UnsettledCondition>  $conditions
     * @param  list<RegisterFact>  $questions  the facts needed, as questions, where the register words them
     */
    public function __construct(
        public string $itemCode,
        public ?Jurisdiction $market,
        public array $conditions = [],
        public array $questions = [],
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

    /**
     * The questions a PRODUCT FORM should ask: facts about the thing sold, answered
     * once and the same in every market. The rest — who buys, what for, how it is
     * billed — are answered per sale and do not belong on a product.
     *
     * @return list<RegisterFact>
     */
    public function productQuestions(): array
    {
        return array_values(array_filter($this->questions, static fn (RegisterFact $fact): bool => $fact->isAboutTheProduct()));
    }
}
