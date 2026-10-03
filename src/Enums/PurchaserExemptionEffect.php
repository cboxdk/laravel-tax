<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/** What a place's rule does for a purchaser. */
enum PurchaserExemptionEffect: string
{
    /** No tax, and no deduction of the input tax behind it. */
    case Exempt = 'exempt';

    /** No tax, the input tax still deductible — the EU's zero-rating of Art. 151. */
    case ExemptWithDeduction = 'exempt_with_deduction';

    /** Taxed as anyone would be: the place grants this purchaser nothing. */
    case Taxable = 'taxable';

    /** Taxed at a lower rate the rule states — Alabama's 0.75% unabated state share. */
    case Reduced = 'reduced';
}
