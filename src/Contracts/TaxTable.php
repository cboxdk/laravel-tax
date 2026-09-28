<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Brick\Money\Money;

/**
 * A tax written as a table rather than a percentage: the amount due on a price, read
 * off rows the authority publishes.
 */
interface TaxTable
{
    /** The tax the table states on an amount; null where it states none. */
    public function taxOn(Money $amount): ?Money;
}
