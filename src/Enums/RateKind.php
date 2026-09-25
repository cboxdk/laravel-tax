<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * Which band of a jurisdiction's rate schedule a rate belongs to.
 *
 * `Zero` and `Exempt` are both 0% to a price and opposite facts to a return.
 * Zero-rating is a taxable supply at a rate of nothing: the seller keeps the right
 * to deduct the input tax behind it. Exemption takes the supply out of tax, and the
 * deduction with it. They are filed in different boxes, and an exempt supply's
 * invoice has to say it is exempt.
 */
enum RateKind: string
{
    case Standard = 'standard';
    case Reduced = 'reduced';
    case Zero = 'zero';
    case Exempt = 'exempt';

    /** Whether the band charges nothing — zero-rated or exempt alike. */
    public function isNil(): bool
    {
        return $this === self::Zero || $this === self::Exempt;
    }
}
