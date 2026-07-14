<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateKind;

/**
 * A tax rate as a percentage (e.g. 25 = 25%), plus provenance: which band it is,
 * where the number came from, and how much to trust it. Rate maths lives here so
 * every regime rounds consistently.
 */
readonly class TaxRate
{
    /** Scale used for the internal fraction before rounding money to minor units. */
    private const FRACTION_SCALE = 12;

    public BigDecimal $percentage;

    public function __construct(
        BigDecimal|string|int $percentage,
        public RateKind $kind = RateKind::Standard,
        public string $source = 'static',
        public Confidence $confidence = Confidence::Authoritative,
    ) {
        $this->percentage = BigDecimal::of($percentage);
    }

    public function isZero(): bool
    {
        return $this->percentage->isZero();
    }

    /** Tax due on a net (tax-exclusive) amount. */
    public function taxOnNet(Money $net): Money
    {
        return $net->multipliedBy($this->fraction(), RoundingMode::HalfUp);
    }

    /** The net amount contained within a gross (tax-inclusive) amount. */
    public function netFromGross(Money $gross): Money
    {
        $divisor = BigDecimal::one()->plus($this->fraction());

        return $gross->dividedBy($divisor, RoundingMode::HalfUp);
    }

    private function fraction(): BigDecimal
    {
        return $this->percentage->dividedBy(100, self::FRACTION_SCALE, RoundingMode::HalfUp);
    }
}
