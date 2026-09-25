<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The tax standing of the seller entity that is issuing the invoice: where it is
 * established, every jurisdiction it is registered in, and its EU One-Stop-Shop
 * standing. This is the seller side of `tax = f(seller registrations, buyer
 * location, product type)` — the multi-entity routing input the billing engine
 * supplies per invoice.
 *
 * `oss` carries the micro-business / OSS opt-in signals that decide whether
 * cross-border EU B2C supplies source at origin or destination (Art. 59c). It is
 * null when the seller has not asserted a status — the EU regime then applies the
 * general destination rule rather than granting micro-business relief it cannot
 * prove.
 */
readonly class SellerRegistrations
{
    /**
     * @param  list<SellerRegistration>  $registrations
     */
    public function __construct(
        public CountryCode $establishment,
        public array $registrations = [],
        public ?OssStatus $oss = null,
    ) {}

    public function isEstablishedIn(CountryCode $country): bool
    {
        return $this->establishment->equals($country);
    }

    public function isRegisteredIn(CountryCode $country, ?DateTimeInterface $on = null): bool
    {
        if ($this->establishment->equals($country)) {
            return true;
        }

        return array_any($this->registrations, fn (SellerRegistration $registration): bool => $registration->country->equals($country) && $this->inForce($registration, $on));
    }

    /**
     * Whether the seller holds a registration in a specific sub-federal
     * jurisdiction (a US state permit, a Canadian province) — the nexus test for
     * sub-federal regimes.
     */
    public function isRegisteredInSubdivision(SubdivisionCode $subdivision, ?DateTimeInterface $on = null): bool
    {
        return array_any($this->registrations, fn (SellerRegistration $registration): bool => $registration->subdivision !== null
            && $registration->subdivision->equals($subdivision)
            && $this->inForce($registration, $on));
    }

    /**
     * Whether the seller holds a subdivision registration under a specific
     * scheme on the date — how a US remote-seller election (an Alabama SSUT
     * account, a Texas Form 01-799 election) rides on the registration it
     * modifies rather than on a parallel structure. The registration's own
     * validity window doubles as the election's: elected in March, the February
     * backfill prices without it.
     */
    public function holdsSubdivisionScheme(SubdivisionCode $subdivision, string $scheme, ?DateTimeInterface $on = null): bool
    {
        return array_any($this->registrations, fn (SellerRegistration $registration): bool => $registration->subdivision !== null
            && $registration->subdivision->equals($subdivision)
            && $registration->scheme === $scheme
            && $this->inForce($registration, $on));
    }

    /**
     * Whether a registration was in force on the date asked about.
     *
     * A null date means "today", which keeps every existing caller working. It is
     * the wrong default for a backfill and the right one for a live invoice, so
     * regimes pass the supply date explicitly.
     */
    private function inForce(SellerRegistration $registration, ?DateTimeInterface $on): bool
    {
        return $registration->coversDate($on ?? new DateTimeImmutable);
    }

    /**
     * Whether the seller holds ANY registration under a scheme, wherever it is filed.
     *
     * A one-stop scheme is the case this answers: an OSS or IOSS number is filed in
     * one Member State and collects for all twenty-seven, so asking whether the
     * seller is registered in the country of the sale is the wrong question about it.
     */
    public function hasScheme(string $scheme, ?DateTimeInterface $on = null): bool
    {
        return array_any($this->registrations, fn (SellerRegistration $registration): bool => $registration->scheme === $scheme && $this->inForce($registration, $on));
    }

    /** The one-stop schemes that collect across the whole Union from one number. */
    public const array UNION_SCHEMES = ['oss', 'ioss'];

    /**
     * Whether a scheme registration covers a supply into the Union.
     *
     * {@see OssStatus} states the same fact and is what the EU regime reads for
     * Art. 59c. This is the other way a seller can state it — as the registration it
     * actually holds, with the window it holds it for — and a host that states it
     * that way was getting `NotRegistered` on every EU sale, because nothing read
     * the scheme the value object has always named.
     */
    public function holdsUnionScheme(?DateTimeInterface $on = null): bool
    {
        return array_any(self::UNION_SCHEMES, fn (string $scheme): bool => $this->hasScheme($scheme, $on));
    }
}
