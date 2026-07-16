<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;

/**
 * One line of an aggregated tax return: the net and tax totals (and the number of
 * taxable supplies) for a single taxing jurisdiction in a single currency. The
 * jurisdiction is a country plus, where the tax is sub-federal (a US state, a
 * Canadian province), its `subdivision` — so a return can drive a per-state /
 * per-member-state filing rather than collapsing everything to the country.
 */
readonly class ReturnLine
{
    public function __construct(
        public CountryCode $country,
        public ?SubdivisionCode $subdivision,
        public string $currency,
        public Money $net,
        public Money $tax,
        public int $count,
    ) {}
}
