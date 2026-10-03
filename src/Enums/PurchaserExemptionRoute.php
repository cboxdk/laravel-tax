<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/** How an exemption reaches the purchaser. */
enum PurchaserExemptionRoute: string
{
    /** At the till: the supply is invoiced without the tax. */
    case AtSource = 'at_source';

    /** Taxed at the till and reclaimed by the purchaser afterwards. */
    case Refund = 'refund';

    /** Either, as the member state chooses (Art. 151(2)); unknown until its rule says. */
    case AtSourceOrRefund = 'at_source_or_refund';
}
