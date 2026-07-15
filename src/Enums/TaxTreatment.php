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

    /**
     * The seller has no obligation to collect tax in the jurisdiction (e.g. no US
     * economic/physical nexus in the buyer's state). Distinct from Exempt (the
     * supply itself is out of scope): here the supply could be taxable, but this
     * seller is not required to charge it.
     */
    case NotRegistered = 'not_registered';

    /** Whether the seller charges tax on the invoice for this treatment. */
    public function chargesTax(): bool
    {
        return $this === self::Standard;
    }
}
