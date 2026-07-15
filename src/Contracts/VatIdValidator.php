<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\ValueObjects\VatIdValidation;

/**
 * Validates a business customer's tax ID against the authoritative registry for
 * its country (EU VIES, HMRC for the UK, ABN Lookup for Australia, …). The result
 * feeds the `customerTaxIdValidated` decision that gates reverse charge.
 *
 * Fail-safe: an implementation returns an *inconclusive* {@see VatIdValidation}
 * rather than throwing when the service is unavailable, so callers charge tax
 * instead of wrongly zero-rating.
 */
interface VatIdValidator
{
    public function supports(CountryCode $country): bool;

    public function validate(CountryCode $country, string $taxId): VatIdValidation;
}
