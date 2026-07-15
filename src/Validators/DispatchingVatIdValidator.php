<?php

declare(strict_types=1);

namespace Cbox\Tax\Validators;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Tax\ValueObjects\VatIdValidation;

/**
 * Routes a validation to the first registered validator that supports the country
 * (VIES for the EU, HMRC for the UK, ABN Lookup for Australia). A country no
 * validator supports returns an inconclusive result — so callers do not
 * reverse-charge on an unverifiable ID.
 */
readonly class DispatchingVatIdValidator implements VatIdValidator
{
    /**
     * @param  list<VatIdValidator>  $validators
     */
    public function __construct(private array $validators) {}

    public function supports(CountryCode $country): bool
    {
        foreach ($this->validators as $validator) {
            if ($validator->supports($country)) {
                return true;
            }
        }

        return false;
    }

    public function validate(CountryCode $country, string $taxId): VatIdValidation
    {
        foreach ($this->validators as $validator) {
            if ($validator->supports($country)) {
                return $validator->validate($country, $taxId);
            }
        }

        return VatIdValidation::inconclusive('unsupported');
    }
}
