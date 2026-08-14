<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * How a per-item price threshold applies once an item reaches it.
 *
 * The three US states that exempt clothing below a price do not work alike, and
 * the difference is the entire tax on an expensive garment:
 *
 *  - **Massachusetts ($175) and Rhode Island ($250)** tax only the amount OVER
 *    the threshold. A $200 sweater in Massachusetts is taxed on $25 — $1.56 at
 *    6.25%, not $12.50.
 *  - **New York ($110)** taxes the ENTIRE item once it reaches the threshold, the
 *    first $110 included. $109.99 is exempt; $110.00 is fully taxable.
 *
 * Carried as a bare figure the two are the same field with opposite meaning, and
 * either guess is materially wrong for the other — wrong in a way that still
 * produces a plausible number on an invoice.
 */
enum ThresholdRule: string
{
    /** Only the amount above the threshold is taxed (MA, RI). */
    case ExcessTaxable = 'excess_taxable';

    /** Reaching the threshold makes the whole item taxable (NY). */
    case Cliff = 'cliff';
}
