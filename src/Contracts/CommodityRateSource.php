<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * A rate source that can also resolve by **commodity code** — a CN code for goods
 * or a CPA code for services, the classifications the EU Commission's own database
 * keys its rate scopes by.
 *
 * This exists because {@see TaxCategory} is deliberately coarse, and coarse is not
 * always enough: Hungary rates meat, fish, milk and eggs at 5% and dairy desserts,
 * flavoured milk and cereals at 18%, and both are "grocery". No single rate is
 * right for the category, so a source with only the category refuses the band and
 * charges the standard rate. Given the supply's CN code it can answer exactly.
 *
 * A SEPARATE contract rather than a fourth parameter on {@see TaxRateSource}: a
 * source that cannot use commodity codes — a static table, a national flat rate —
 * should not have to pretend it can, and existing implementations keep working
 * untouched.
 */
interface CommodityRateSource extends TaxRateSource
{
    /**
     * The rate for a supply whose commodity code is known.
     *
     * A null or unrecognised code must behave exactly like
     * {@see TaxRateSource::rateFor()} — the code refines an answer, it never
     * withholds one.
     */
    public function rateForCommodity(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?string $commodityCode,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate;
}
