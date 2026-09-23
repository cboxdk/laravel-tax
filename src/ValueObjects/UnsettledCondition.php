<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * One published condition on a rate that the facts given could not settle, and what
 * would settle it.
 *
 * This is the answer to "what do I need to tell you about this product?" — asked of a
 * catalogue when a seller opens a market, not of a checkout. `settledByCommodityCode`
 * says a CN or HS code on the product would answer it without any other fact; `facts`
 * names the register's facts that would, in the register's own words, for the cases a
 * code cannot express. Both empty means the register published only the statute's
 * sentence, and nothing a seller supplies can settle it.
 */
final readonly class UnsettledCondition
{
    /**
     * @param  list<string>  $facts
     */
    public function __construct(
        public string $kind,
        public string $says,
        public ?string $names = null,
        public array $facts = [],
        public bool $settledByCommodityCode = false,
    ) {}

    /** Whether anything a seller can supply would settle it. */
    public function isSettleable(): bool
    {
        return $this->settledByCommodityCode || $this->facts !== [];
    }
}
