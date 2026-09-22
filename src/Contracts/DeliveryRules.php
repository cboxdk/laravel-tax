<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\DeliveryComponent;
use DateTimeImmutable;

interface DeliveryRules
{
    /** Null means unknown. False still requires satisfaction of any exclusion conditions. */
    public function included(SubdivisionCode $state, DeliveryComponent $component, bool $goodsTaxable, DateTimeImmutable $at): ?bool;
}
