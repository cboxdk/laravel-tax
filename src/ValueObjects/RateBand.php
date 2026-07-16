<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Tax\Enums\RateKind;

/**
 * A non-standard rate band a jurisdiction applies to a specific taxability
 * category — a reduced or zero rate for e-books, food, passenger transport, etc.
 * A rate source keys these by (jurisdiction, category) so a supply that legally
 * carries a reduced band resolves one instead of the standard rate.
 *
 * This is the STRUCTURE for reduced rates; the percentages themselves are DATA
 * that must be supplied by a bound source or feed — the package ships none by
 * default rather than fabricate a national reduced-rate table.
 */
readonly class RateBand
{
    public function __construct(
        public string $percentage,
        public RateKind $kind = RateKind::Reduced,
    ) {}
}
