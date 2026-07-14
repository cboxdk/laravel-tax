<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Geo\ValueObjects\CountryCode;

/**
 * A tax registration a seller entity holds in a jurisdiction. `scheme` records
 * how it is registered (e.g. "domestic", "oss", "local", "simplified") so the
 * regime can distinguish a local registration from a one-stop-shop scheme.
 */
readonly class SellerRegistration
{
    public function __construct(
        public CountryCode $country,
        public ?string $scheme = null,
    ) {}
}
