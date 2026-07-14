<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * The tax treatment the engine determined for a supply.
 *
 * `ReverseCharge` means no tax is charged by the seller because the business
 * customer self-accounts; it is distinct from `ZeroRated` (a real 0% rate) and
 * `Exempt` (outside the scope of tax).
 */
enum TaxTreatment: string
{
    case Standard = 'standard';
    case ReverseCharge = 'reverse_charge';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';

    /** Whether the seller charges tax on the invoice for this treatment. */
    public function chargesTax(): bool
    {
        return $this === self::Standard;
    }
}
