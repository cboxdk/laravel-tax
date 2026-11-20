<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\ValueObjects\SmallBusinessRule;
use DateTimeImmutable;

/**
 * A member state's small-business exemption on a date; null where the register
 * publishes none, which is a refusal and never "no threshold".
 */
interface SmallBusinessRules
{
    public function for(CountryCode $state, DateTimeImmutable $on): ?SmallBusinessRule;

    /**
     * Every state the release publishes the exemption for.
     *
     * @return array<string, SmallBusinessRule>
     */
    public function all(DateTimeImmutable $on): array;
}
