<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Tax\Enums\MarginGoods;
use Cbox\Tax\Enums\MarginMethod;

/**
 * What the margin scheme did to one supply, beside the amounts.
 *
 * `margin` is the selling price less the purchase price, tax included, and never
 * below zero: a sale at a loss owes nothing and gives nothing back. `taxComputable`
 * is false under the per-period method, where the assessment's tax is zero because
 * no figure exists for one sale — not because none is due.
 */
readonly class MarginResult
{
    public function __construct(
        public MarginGoods $goods,
        public MarginMethod $method,
        public Money $purchasePrice,
        public Money $margin,
        public bool $taxComputable = true,
    ) {}
}
