<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\CountryCode;
use DateTimeImmutable;

/**
 * Whether a jurisdiction's law makes an online marketplace the party liable to
 * collect on the supplies it facilitates, on a given date.
 *
 * The caller asserts THAT a sale went through a platform which took on collection —
 * only the caller can know that. This answers the other half: whether the law of the
 * place of supply actually moves the liability. Where it does not, the assertion
 * changes nothing and the seller's own obligation stands.
 */
interface MarketplaceRules
{
    public function platformOwes(CountryCode $country, DateTimeImmutable $on): bool;
}
