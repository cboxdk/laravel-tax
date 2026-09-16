<?php

declare(strict_types=1);

namespace Cbox\Tax\Taxability;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\ValueObjects\TaxDetermination;
use DateTimeImmutable;

/**
 * Everything is taxable. The floor a regime falls to when nobody supplied a
 * taxability source at all.
 *
 * It is not a guess dressed as an answer: general tangible goods are taxable at the
 * standard rate in every sales-tax state and every member state, so a caller who
 * bound nothing is over-charged rather than under-charged, and that is the direction
 * a refund can fix. What it must never do is quietly exempt something — an
 * under-charge is discovered at audit, years later, with interest.
 *
 * The register answers this properly; this exists so
 * {@see DefaultRegimeRegistry::withDefaults()} can be called with
 * nothing, which is how the regimes are unit-tested.
 */
final readonly class AlwaysTaxable implements ProductTaxability
{
    public function determine(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        Money $amount,
        ?DateTimeImmutable $at = null,
    ): TaxDetermination {
        return TaxDetermination::taxable();
    }
}
