<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\ValueObjects\TaxDetermination;
use DateTimeImmutable;

/**
 * Taxability for a register CATEGORY KEY. The optional companion to
 * {@see CategoryKeyedRateSource}; the same rules apply.
 */
interface CategoryKeyedTaxability extends ProductTaxability
{
    public function determineKey(
        Jurisdiction $jurisdiction,
        string $categoryKey,
        Money $amount,
        ?DateTimeImmutable $at = null,
    ): TaxDetermination;
}
