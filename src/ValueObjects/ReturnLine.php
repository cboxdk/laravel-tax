<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\CountryCode;

/**
 * One line of an aggregated tax return: the net and tax totals (and the number of
 * taxable supplies) for a jurisdiction in a single currency.
 */
readonly class ReturnLine
{
    public function __construct(
        public CountryCode $country,
        public string $currency,
        public Money $net,
        public Money $tax,
        public int $count,
    ) {}
}
