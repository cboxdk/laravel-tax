<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * One rate as a register release files it — the record, not an answer for a supply.
 *
 * For comparing what a jurisdiction's rates were in one release against another, or
 * showing them; pricing a supply goes through `TaxRateSource`, which applies the
 * rules a record alone does not carry.
 */
readonly class RateRecord
{
    public function __construct(
        /** The register's code for the jurisdiction that levies it. */
        public string $jurisdiction,
        /** The register's band: `standard`, `reduced`, `zero`, `exempt`, `local_component`… */
        public string $kind,
        /** Null where the rate is a bracket table or a per-unit amount. */
        public ?BigDecimal $percentage = null,
        /** The category the rate is filed at; null is the jurisdiction's general rate. */
        public ?string $category = null,
        /** The classification scheme a rate is scoped to, e.g. `cn`. */
        public ?string $classificationScheme = null,
        /** The code within that scheme, e.g. `0401 10`. */
        public ?string $classificationCode = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $until = null,
    ) {}

    /** Whether the record is in force on the date. */
    public function inForceOn(DateTimeImmutable $day): bool
    {
        $date = $day->format('Y-m-d');

        return ($this->from === null || $this->from->format('Y-m-d') <= $date)
            && ($this->until === null || $this->until->format('Y-m-d') >= $date);
    }
}
