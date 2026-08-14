<?php

declare(strict_types=1);

namespace Cbox\Tax\Validators;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Tax\ValueObjects\VatIdValidation;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Validates a UK VAT registration number against HMRC's public "Check a UK VAT
 * number" API. A 404 is a conclusive "not registered"; a transport error is
 * inconclusive (fail-safe).
 *
 * A conclusive VALID requires the response to POSITIVELY identify the registration
 * — HMRC echoes the number it looked up in `target.vatNumber` alongside the
 * registered `target.name`, and both must be present and the number must match
 * what was asked. A 2xx alone proves nothing: an empty body, an error envelope, a
 * captive portal serving JSON, or a future response shape would all otherwise
 * become "conclusively valid" and zero-rate a UK B2B supply that was never
 * verified. Anything short of the echo is inconclusive, so tax is charged.
 */
readonly class HmrcVatValidator implements VatIdValidator
{
    public function __construct(
        private Factory $http,
        private string $baseUrl = 'https://api.service.hmrc.gov.uk',
    ) {}

    public function supports(CountryCode $country): bool
    {
        return $country->value === 'GB';
    }

    public function validate(CountryCode $country, string $taxId): VatIdValidation
    {
        $vrn = (string) preg_replace('/[^0-9]/', '', $taxId);

        try {
            $response = $this->http
                ->acceptJson()
                ->get($this->baseUrl.'/organisations/vat/check-vat-number/lookup/'.$vrn);
        } catch (Throwable) {
            return VatIdValidation::inconclusive('hmrc');
        }

        if ($response->status() === 404) {
            return VatIdValidation::invalid('hmrc');
        }

        if (! $response->successful()) {
            return VatIdValidation::inconclusive('hmrc');
        }

        $data = $response->json();

        if (! is_array($data)) {
            return VatIdValidation::inconclusive('hmrc');
        }

        $target = $data['target'] ?? null;

        if (! is_array($target)) {
            return VatIdValidation::inconclusive('hmrc');
        }

        $name = $this->string($target, 'name');
        $echoed = $this->string($target, 'vatNumber');

        // The echoed number must be the one we asked about. Without this check a
        // response for a different registration — or no registration at all —
        // would be accepted as proof for this one.
        if ($name === null || $echoed === null || $this->digits($echoed) !== $vrn) {
            return VatIdValidation::inconclusive('hmrc');
        }

        return VatIdValidation::valid(
            source: 'hmrc',
            name: $name,
            address: $this->addressOf($target),
            consultationReference: $this->string($data, 'consultationNumber'),
        );
    }

    /**
     * HMRC returns the registered address as an object of optional lines; join the
     * ones present so the audit trail carries what the service actually said.
     *
     * @param  array<array-key, mixed>  $target
     */
    private function addressOf(array $target): ?string
    {
        $address = $target['address'] ?? null;

        if (! is_array($address)) {
            return null;
        }

        $parts = [];

        foreach (['line1', 'line2', 'line3', 'line4', 'postcode', 'countryCode'] as $key) {
            $part = $this->string($address, $key);

            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function digits(string $value): string
    {
        return (string) preg_replace('/[^0-9]/', '', $value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
