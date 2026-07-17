<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * How a state's economic-nexus dollar and transaction thresholds combine to
 * establish nexus.
 */
enum NexusCombinator: string
{
    /** Only the sales-dollar threshold applies (no transaction count). */
    case SalesOnly = 'sales_only';

    /** Either the sales OR the transaction threshold establishes nexus. */
    case SalesOrTransactions = 'sales_or_transactions';

    /** BOTH the sales AND the transaction threshold must be met (e.g. Connecticut, New York). */
    case SalesAndTransactions = 'sales_and_transactions';
}
