<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use DateTimeImmutable;

/**
 * How many units of one currency one unit of another buys, as a named source
 * published it on a named date — what an invoice cites beside the converted tax.
 */
readonly class ExchangeRate
{
    public function __construct(
        /** ISO 4217, e.g. `USD`. */
        public string $from,
        /** ISO 4217, e.g. `DKK`. */
        public string $to,
        /** Units of `to` per one unit of `from`. */
        public BigDecimal $rate,
        /** The date the source published the rate for. */
        public DateTimeImmutable $publishedOn,
        /** Who published it: `ecb` for the European Central Bank. */
        public string $source,
    ) {}

    /** The amount in the target currency, rounded half-up at its minor unit. */
    public function convert(Money $amount): Money
    {
        return Money::of($amount->getAmount()->multipliedBy($this->rate), $this->to, roundingMode: RoundingMode::HalfUp);
    }
}
