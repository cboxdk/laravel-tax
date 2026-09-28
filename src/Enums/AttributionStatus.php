<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * Who accounts for the tax on a supply in a member state by a supplier not established
 * there, as the state's published rule (Art. 194) answers it for this supply.
 */
enum AttributionStatus: string
{
    /** The customer accounts for it: a reverse charge. */
    case RecipientAccounts = 'recipient_accounts';

    /** The supplier does, and charges the state's VAT. */
    case SupplierAccounts = 'supplier_accounts';

    /** The rule's conditions need facts this supply does not state. */
    case Unsettled = 'unsettled';

    /** The state publishes a rule that does not say which supplies it covers. */
    case Unscoped = 'unscoped';

    /** The state publishes no rule for the date. */
    case NotPublished = 'not_published';
}
