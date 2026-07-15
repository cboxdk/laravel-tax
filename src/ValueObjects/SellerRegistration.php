<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;

/**
 * A tax registration a seller entity holds in a jurisdiction. For sub-federal
 * jurisdictions (US state permits, Canadian provinces) it carries the specific
 * `subdivision`; `scheme` records how it is registered (e.g. "domestic", "oss",
 * "simplified") so the regime can distinguish a local registration from a
 * one-stop scheme.
 */
readonly class SellerRegistration
{
    public function __construct(
        public CountryCode $country,
        public ?SubdivisionCode $subdivision = null,
        public ?string $scheme = null,
    ) {}
}
