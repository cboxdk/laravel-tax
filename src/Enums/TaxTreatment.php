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

    /**
     * The supply is taxable and the tax IS being collected — by the marketplace,
     * not by this seller.
     *
     * Every US state with a sales tax now makes a qualifying marketplace the party
     * liable to collect and remit on its third-party sellers' supplies (Missouri was
     * the last, on 2023-01-01), and the EU does the same through the Art. 14a deemed
     * supplier rule for electronic interfaces. The seller charges nothing.
     *
     * KEPT APART FROM `Exempt` AND `NotRegistered`, and the distinction is not
     * cosmetic — all three produce a zero charge and they mean opposite things on a
     * return. `Exempt` says no tax was due. `NotRegistered` says this seller had no
     * obligation in that state. This says tax was due and somebody else remitted it,
     * and most states still expect the seller to REPORT the sale in gross receipts
     * and then deduct it as marketplace-facilitated. A treatment that collapsed
     * these would file a wrong return while charging the right amount.
     */
    case MarketplaceFacilitated = 'marketplace_facilitated';

    /** Whether the seller charges tax on the invoice for this treatment. */
    public function chargesTax(): bool
    {
        return $this === self::Standard;
    }

    /**
     * Whether tax was actually due on the supply, whoever ends up remitting it.
     *
     * True for a standard charge and for a marketplace-facilitated one; false where
     * nothing was owed or this seller owed nothing. Reverse charge is TRUE — the
     * tax is real and the customer self-accounts for it.
     */
    public function taxWasDue(): bool
    {
        return match ($this) {
            self::Standard, self::MarketplaceFacilitated, self::ReverseCharge => true,
            self::ZeroRated, self::Exempt, self::NotRegistered => false,
        };
    }
}
