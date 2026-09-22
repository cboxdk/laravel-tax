<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * A rate source that answers for a register CATEGORY KEY, not only a {@see TaxClass}.
 *
 * Optional, like {@see CommodityRateSource}: the engine checks for it when a query
 * names a `categoryKey`, and refuses rather than guessing when the bound source
 * cannot answer one. A source keyed on its own vocabulary has no reason to implement
 * it.
 *
 * The key is the register's own — `services.education`, `goods.medical_equipment.prosthetic`
 * — and an implementation must refuse one the installed release does not publish.
 * That check is the point: an enum catches a typo at compile time, and only the
 * published vocabulary can catch a real key the pinned release does not carry.
 */
interface CategoryKeyedRateSource extends TaxRateSource
{
    public function rateForKey(
        Jurisdiction $jurisdiction,
        string $categoryKey,
        ?string $commodityCode = null,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate;
}
