<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\ValueObjects\TaxRounding;
use DateTimeImmutable;

interface RoundingRules
{
    public function for(SubdivisionCode $state, DateTimeImmutable $at): ?TaxRounding;
}
