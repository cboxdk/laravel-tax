<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Geo\ValueObjects\CountryCode;

/**
 * The tax standing of the seller entity that is issuing the invoice: where it is
 * established, and every jurisdiction it is registered in. This is the seller
 * side of `tax = f(seller registrations, buyer location, product type)` — the
 * multi-entity routing input the billing engine supplies per invoice.
 */
readonly class SellerRegistrations
{
    /**
     * @param  list<SellerRegistration>  $registrations
     */
    public function __construct(
        public CountryCode $establishment,
        public array $registrations = [],
    ) {}

    public function isEstablishedIn(CountryCode $country): bool
    {
        return $this->establishment->equals($country);
    }

    public function isRegisteredIn(CountryCode $country): bool
    {
        if ($this->establishment->equals($country)) {
            return true;
        }

        foreach ($this->registrations as $registration) {
            if ($registration->country->equals($country)) {
                return true;
            }
        }

        return false;
    }

    public function hasScheme(string $scheme): bool
    {
        foreach ($this->registrations as $registration) {
            if ($registration->scheme === $scheme) {
                return true;
            }
        }

        return false;
    }
}
