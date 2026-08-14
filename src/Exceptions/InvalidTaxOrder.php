<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Cbox\Tax\ValueObjects\TaxOrder;
use InvalidArgumentException;

/**
 * Raised when a {@see TaxOrder} cannot be a document — no lines, or lines in more
 * than one currency.
 *
 * Both are caught at construction rather than allowed to surface later: an empty
 * order would produce totals with no currency to express them in, and mixed
 * currencies would fail deep inside `Money::plus` with a message about currency
 * codes rather than about the invoice that is wrong.
 */
class InvalidTaxOrder extends InvalidArgumentException
{
    public static function withoutLines(): self
    {
        return new self('A tax order needs at least one line; there is nothing to assess and no currency to total in.');
    }

    public static function unidentifiedLine(): self
    {
        return new self('Every line needs a non-empty id: it is how the assessment is mapped back onto your invoice rows.');
    }

    public static function duplicateLineId(string $lineId): self
    {
        return new self(sprintf(
            'Two lines share the id "%s". The totals would count both while a lookup found only the first, putting one line\'s tax on the other\'s invoice row.',
            $lineId,
        ));
    }

    public static function mixedCurrencies(string $expected, string $found, string $lineId): self
    {
        return new self(sprintf(
            'A tax order is settled and filed in one currency, but line "%s" is in %s where the order is in %s. Convert before assessing, or split the document.',
            $lineId,
            $found,
            $expected,
        ));
    }
}
