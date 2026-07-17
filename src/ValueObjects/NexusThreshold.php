<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Tax\Enums\NexusCombinator;

/**
 * A US state's economic-nexus threshold: the annual sales-dollar figure and,
 * where the state still applies one, the transaction count, plus how the two
 * combine ({@see NexusCombinator}). These are the *Wayfair* remote-seller
 * thresholds — the point at which a seller with no physical presence nonetheless
 * has an obligation to register and collect.
 *
 * This is DATA (published per state, and largely stable); the engine does not
 * evaluate it automatically per invoice, because economic nexus depends on the
 * seller's CUMULATIVE sales/transactions in the state over a measuring period,
 * which a single supply does not carry. The host supplies those running totals to
 * {@see isMet()} to determine whether the seller has likely crossed the threshold.
 */
readonly class NexusThreshold
{
    public function __construct(
        public int $salesDollars,
        public ?int $transactions,
        public NexusCombinator $combinator,
    ) {}

    /**
     * Whether the given cumulative sales (in whole dollars) and transaction count
     * meet this state's economic-nexus threshold.
     */
    public function isMet(int $salesDollars, int $transactions): bool
    {
        $salesMet = $salesDollars >= $this->salesDollars;
        $transactionsMet = $this->transactions !== null && $transactions >= $this->transactions;

        return match ($this->combinator) {
            NexusCombinator::SalesOnly => $salesMet,
            NexusCombinator::SalesOrTransactions => $salesMet || $transactionsMet,
            NexusCombinator::SalesAndTransactions => $salesMet && $transactionsMet,
        };
    }

    /** A short human-readable description, e.g. "$100,000 or 200 transactions". */
    public function describe(): string
    {
        $sales = '$'.number_format($this->salesDollars);

        if ($this->transactions === null) {
            return $sales;
        }

        $joiner = $this->combinator === NexusCombinator::SalesAndTransactions ? ' and ' : ' or ';

        return $sales.$joiner.number_format($this->transactions).' transactions';
    }
}
