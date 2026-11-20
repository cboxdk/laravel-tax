<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\CountryCode;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The seller applies the small-business exemption in one member state (Arts. 284–288a
 * of the VAT Directive as amended by Directive (EU) 2020/285: the Dutch KOR, the
 * German Kleinunternehmerregelung).
 *
 * PER SELLER AND PER STATE. In its own state a seller qualifies under that state's
 * threshold. In another it also needs the identification number its own state issued
 * for the cross-border scheme (suffix `EX`) and a Union turnover within the cap.
 *
 * THE ENGINE NEVER GUESSES TURNOVER, the rule {@see OssStatus} already keeps.
 * `turnover` is what the seller has turned over in this state so far in the calendar
 * year of the supply, without the supply being assessed, in the threshold's currency;
 * the register holds the threshold, and the engine decides whether this supply is the
 * one that passes it. `unionTurnoverExceeded` is the host's measurement against the
 * Union cap, in the current or the previous calendar year.
 */
readonly class SmallBusinessExemption
{
    public function __construct(
        public CountryCode $country,
        public Money $turnover,
        public bool $unionTurnoverExceeded = false,
        /** The cross-border identification number (suffix `EX`), where the seller holds one. */
        public ?string $identificationNumber = null,
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validUntil = null,
        /**
         * The seller's turnover in this state in the PREVIOUS calendar year, where
         * the host knows it. A state that shuts a seller out for the year after it
         * passed the threshold (the Netherlands does) is asked with it; null says
         * nothing, and the claim stands on the current year alone.
         */
        public ?Money $previousYearTurnover = null,
    ) {}

    public function coversDate(DateTimeInterface $at): bool
    {
        $date = $at->format('Y-m-d');

        return ($this->validFrom === null || $this->validFrom->format('Y-m-d') <= $date)
            && ($this->validUntil === null || $date <= $this->validUntil->format('Y-m-d'));
    }
}
