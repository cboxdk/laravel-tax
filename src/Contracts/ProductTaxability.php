<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\ValueObjects\TaxDetermination;
use DateTimeImmutable;

/**
 * Decides whether a product category is taxable in a jurisdiction — the
 * taxability-matrix seam. It matters most for US sales tax, where SaaS/digital
 * taxability varies state by state; national VAT/GST regimes generally tax at the
 * standard rate and do not consult it.
 *
 * Sourced DATA, like rates: bind a matrix backed by an authoritative source (e.g.
 * the SST taxability matrices) in production. Implementations may throw when a
 * category/jurisdiction is unknown; callers should treat that as a hard block,
 * not as taxable or exempt.
 */
interface ProductTaxability
{
    /**
     * What this jurisdiction does to the category, for an amount, on a date.
     *
     * `$amount` is here because taxability is not always a property of the product
     * alone. Massachusetts exempts clothing below $175, New York below $110, Rhode
     * Island below $250 — and the two mechanics differ, so the same garment is
     * taxed on $25 in Boston and on the full price in Buffalo. A seam returning
     * `bool` had two ways to handle that and both were wrong: charge every exempt
     * garment, or refuse the line. It refused, which at a checkout is a lost sale
     * over a rule the dataset had the figures for.
     *
     * `$at` is the date the supply is assessed on, null meaning today. Taxability
     * is dated law, not a standing fact: states move categories in and out of tax
     * on their own schedule, and evaluating those windows against the current date
     * priced a reissued invoice with that year's RATE and this year's TAXABILITY.
     */
    public function determine(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        Money $amount,
        ?DateTimeImmutable $at = null,
    ): TaxDetermination;
}
