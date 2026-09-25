<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * What a state's published rule says about a delivery charge.
 *
 * `$conditionsVerified` is the half that matters when `$included` is false. A
 * register rule published as a bare `included: false` states an exclusion whose
 * conditions live in prose — separately stated, labelled as delivery, the true cost —
 * and the host still has to confirm them before freight comes off the base. A rule
 * published as a decision has tested those same conditions against facts the host
 * supplied, so there is nothing left to confirm.
 */
readonly class DeliveryTreatment
{
    public function __construct(
        public bool $included,
        public bool $conditionsVerified,
    ) {}
}
