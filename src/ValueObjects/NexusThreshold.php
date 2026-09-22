<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Tax\Enums\NexusCombinator;
use Cbox\Tax\Enums\ThresholdOperator;

/**
 * A US state's economic-nexus threshold: the annual sales-dollar figure and,
 * where the state still applies one, the transaction count, plus how the two
 * combine ({@see NexusCombinator}). These are the *Wayfair* remote-seller
 * thresholds — the point at which a seller with no physical presence nonetheless
 * has an obligation to register and collect.
 *
 * This carries the FIGURES so a `NotRegistered` outcome can name them — nothing
 * more. It deliberately cannot decide whether a seller has crossed a threshold,
 * and {@see describe()} is its whole purpose.
 *
 * The verdict is a different question and a harder one. It turns on the state's
 * measuring PERIOD (rolling twelve months, previous calendar year, the four
 * preceding VAT quarters) and on which sales BASIS it measures (gross, retail,
 * taxable) — neither of which this object carries, and neither of which a single
 * supply can supply. An answer given without them is a confident guess dressed as
 * a determination, so this package does not offer one. `cboxdk/laravel-nexus`
 * models both and returns `Unknown` rather than guessing when the seller's totals
 * are measured on a different footing than the state uses.
 */
readonly class NexusThreshold
{
    public function __construct(
        public int $salesDollars,
        public ?int $transactions,
        public NexusCombinator $combinator,
        /** How the sales figure is crossed, where the register states it. */
        public ?ThresholdOperator $salesOperator = null,
        /** How the transaction count is crossed, where the register states it. */
        public ?ThresholdOperator $transactionsOperator = null,
    ) {}

    /**
     * A short human-readable description, e.g. "$100,000 or 200 transactions", or
     * "more than $500,000 and more than 100 transactions" where the register says how
     * each figure is crossed.
     */
    public function describe(): string
    {
        $sales = self::limb('$'.number_format($this->salesDollars), $this->salesOperator);

        if ($this->transactions === null) {
            return $sales;
        }

        $joiner = $this->combinator === NexusCombinator::SalesAndTransactions ? ' and ' : ' or ';

        return $sales.$joiner.self::limb(number_format($this->transactions).' transactions', $this->transactionsOperator);
    }

    private static function limb(string $figure, ?ThresholdOperator $operator): string
    {
        return match ($operator) {
            ThresholdOperator::Exceeds => 'more than '.$figure,
            ThresholdOperator::AtLeast => $figure.' or more',
            null => $figure,
        };
    }
}
