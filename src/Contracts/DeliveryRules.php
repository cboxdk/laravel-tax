<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\ValueObjects\DeliveryCharge;
use Cbox\Tax\ValueObjects\DeliveryTreatment;
use DateTimeImmutable;

interface DeliveryRules
{
    /**
     * Whether a state's taxable base includes this delivery charge.
     *
     * Null means no rule applies on that date. A treatment with `included: false` and
     * `conditionsVerified: false` still needs the host's confirmation of the published
     * exclusion conditions; a rule that cannot be decided from the facts supplied —
     * a published decision reaching `unknown` or `unsupported` — refuses.
     *
     * `$delivery->goodsTaxable` must be known before this is asked.
     */
    public function treatment(SubdivisionCode $state, DeliveryCharge $delivery, DateTimeImmutable $at): ?DeliveryTreatment;
}
