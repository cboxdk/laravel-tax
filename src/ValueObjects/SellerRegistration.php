<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * A tax registration a seller entity holds in a jurisdiction. For sub-federal
 * jurisdictions (US state permits, Canadian provinces) it carries the specific
 * `subdivision`; `scheme` records how it is registered (e.g. "domestic", "oss",
 * "simplified") so the regime can distinguish a local registration from a
 * one-stop scheme.
 *
 * A registration has a LIFETIME, and the engine must respect it. A seller who
 * registered in Texas on 1 March did not owe Texas tax in February, and one who
 * deregistered in June does not owe it in July. Without the window every backfill
 * of historical invoices — the first thing a new customer does — applies today's
 * registrations to last year's supplies and produces a return for a period the
 * seller was not registered in.
 *
 * Both bounds are inclusive of their day and null means open-ended, matching
 * {@see TaxExemption}, which has carried a window from the start. Leaving both
 * null keeps the old always-registered behaviour.
 */
readonly class SellerRegistration
{
    public function __construct(
        public CountryCode $country,
        public ?SubdivisionCode $subdivision = null,
        public ?string $scheme = null,
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validUntil = null,
    ) {}

    /** Whether the registration was in force on the given date. */
    public function coversDate(DateTimeInterface $at): bool
    {
        $date = $at->format('Y-m-d');

        return ($this->validFrom === null || $this->validFrom->format('Y-m-d') <= $date)
            && ($this->validUntil === null || $date <= $this->validUntil->format('Y-m-d'));
    }
}
