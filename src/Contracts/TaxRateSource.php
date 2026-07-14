<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * Supplies the rate DATA the engine applies. This is the one part of tax the
 * engine does not own: rates change and must be sourced (an official EU feed, the
 * SST files, a commercial adapter). Implementations return `null` when they have
 * no rate for the jurisdiction — the engine then denies rather than assuming 0%.
 */
interface TaxRateSource
{
    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxCategory $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate;
}
