<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Tax\Contracts\TaxTable;

/**
 * Tables each read on their own and added: Pennsylvania computes its 1% local tax on
 * its own $10 table and adds it to the state's 6% table — which is not the same as one
 * 7% figure read on the total. Null if any part cannot be read, since a total short by
 * one table's share is not a total.
 */
readonly class CombinedTaxTable implements TaxTable
{
    /** @param  list<TaxTable>  $tables */
    public function __construct(public array $tables) {}

    public function taxOn(Money $amount): ?Money
    {
        $total = null;

        foreach ($this->tables as $table) {
            $part = $table->taxOn($amount);

            if ($part === null) {
                return null;
            }

            $total = $total === null ? $part : $total->plus($part);
        }

        return $total;
    }
}
