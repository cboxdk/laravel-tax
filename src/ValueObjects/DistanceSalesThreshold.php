<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Tax\Enums\ThresholdOperator;

/**
 * The EU's threshold for cross-border sales to consumers (Art. 59c of the VAT
 * Directive): below it, an established business may charge its own country's VAT on
 * those sales; above it, the customer's.
 *
 * Reported, not applied. Measuring a seller's turnover against it is the host's —
 * the engine is told the outcome through the seller's registrations.
 */
readonly class DistanceSalesThreshold
{
    public function __construct(
        /** €10,000, as the register publishes it. */
        public Money $amount,
        /** How the amount is crossed, where the register states it: `Exceeds` — €10,000 exactly is still below. */
        public ?ThresholdOperator $operator = null,
        /**
         * The periods each measured on its own; crossing in any one counts.
         * `previous_calendar_year` and `calendar_year` under the Directive.
         *
         * @var list<string>
         */
        public array $measuredOver = [],
    ) {}
}
