<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Math\BigDecimal;

/** One line of a published bracket table: from 0.18 up to 0.34, the tax is 0.02. */
readonly class BracketRow
{
    public function __construct(
        public BigDecimal $from,
        public BigDecimal $upTo,
        public BigDecimal $tax,
    ) {}

    public function covers(BigDecimal $amount): bool
    {
        return $amount->isGreaterThanOrEqualTo($this->from) && $amount->isLessThanOrEqualTo($this->upTo);
    }
}
