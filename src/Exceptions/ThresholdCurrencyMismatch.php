<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Cbox\Tax\Enums\RefusalReason;
use Cbox\Tax\ValueObjects\TaxDetermination;
use RuntimeException;

/**
 * A price threshold stated in one currency was met with an amount in another.
 *
 * Three states exempt clothing below a per-item price — Massachusetts at $175,
 * New York at $110, Rhode Island at $250 — and those are dollar figures in the
 * statute, not abstract quantities. Comparing them against a line denominated in
 * something else needs an exchange rate on the supply date, which this package
 * does not have and must not invent: the number it invented would decide whether
 * the line is taxed at all.
 *
 * The behaviour this replaced was quieter and worse. The threshold travelled as
 * minor units alone, so `11000` was read as whatever the invoice's currency
 * counts in — ¥11,000 against a yen invoice, 11 dinar against a Bahraini one —
 * and the resulting figure was plausible enough to pass unread.
 *
 * Selling into a US state in a non-USD currency is a real thing to want to do.
 * The answer is for the host to convert the line to the currency of the place
 * being taxed before asking, since it is the host that knows which rate its
 * accounting uses.
 *
 * @see TaxDetermination::taxableBase()
 */
class ThresholdCurrencyMismatch extends RuntimeException implements Refusal
{
    public static function between(string $threshold, string $amount): self
    {
        return new self(sprintf(
            'The exemption threshold is stated in %s but the amount is in %s. Convert the line to %s '
            .'before assessing it — this package will not choose an exchange rate on your behalf, because '
            .'that choice decides whether the line is taxed.',
            $threshold,
            $amount,
            $threshold,
        ));
    }

    public function reason(): RefusalReason
    {
        return RefusalReason::ThresholdCurrencyUnknown;
    }
}
