<?php

declare(strict_types=1);

namespace Cbox\Tax\Testing;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Tax\ValueObjects\VatIdValidation;

/**
 * A configurable in-memory validator for tests — no network. Unconfigured lookups
 * return inconclusive (the fail-safe default).
 */
class FakeVatIdValidator implements VatIdValidator
{
    /** @var array<string, VatIdValidation> */
    private array $results = [];

    public function willReturn(CountryCode $country, string $taxId, VatIdValidation $result): self
    {
        $this->results[$country->value.'|'.$taxId] = $result;

        return $this;
    }

    public function supports(CountryCode $country): bool
    {
        return true;
    }

    public function validate(CountryCode $country, string $taxId): VatIdValidation
    {
        return $this->results[$country->value.'|'.$taxId] ?? VatIdValidation::inconclusive('fake');
    }
}
