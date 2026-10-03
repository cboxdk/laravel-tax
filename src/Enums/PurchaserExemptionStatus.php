<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/** Whether a place's rule for a purchaser applies to this supply. */
enum PurchaserExemptionStatus: string
{
    /** The rule's conditions hold: its effect is the answer. */
    case Applies = 'applies';

    /** A condition is false: the purchaser gets nothing here for this supply. */
    case DoesNotApply = 'does_not_apply';

    /** The rule turns on facts the supply does not state. */
    case Unsettled = 'unsettled';

    /** The register states nothing for this purchaser in this place on the date. */
    case NotPublished = 'not_published';
}
