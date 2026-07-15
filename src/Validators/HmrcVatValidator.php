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
        $name = is_array($target) ? $this->string($target, 'name') : null;

        return VatIdValidation::valid(
            source: 'hmrc',
            name: $name,
            consultationReference: $this->string($data, 'consultationNumber'),
        );
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
