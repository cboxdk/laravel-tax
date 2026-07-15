<?php

declare(strict_types=1);

namespace Cbox\Tax\Validators;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Tax\ValueObjects\VatIdValidation;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Validates an Australian Business Number against the ABR ABN Lookup web service.
 * Requires a registered ABN Lookup GUID. An ABN whose status is not "Active" is a
 * conclusive invalid; a transport error is inconclusive (fail-safe).
 */
readonly class AbnLookupValidator implements VatIdValidator
{
    public function __construct(
        private Factory $http,
        private string $guid,
        private string $baseUrl = 'https://abr.business.gov.au',
    ) {}

    public function supports(CountryCode $country): bool
    {
        return $country->value === 'AU';
    }

    public function validate(CountryCode $country, string $taxId): VatIdValidation
    {
        $abn = (string) preg_replace('/[^0-9]/', '', $taxId);

        try {
            $response = $this->http->get($this->baseUrl.'/json/AbnDetails.aspx', [
                'abn' => $abn,
                'guid' => $this->guid,
            ]);
        } catch (Throwable) {
            return VatIdValidation::inconclusive('abn');
        }

        if (! $response->successful()) {
            return VatIdValidation::inconclusive('abn');
        }

        $data = $response->json();

        if (! is_array($data)) {
            return VatIdValidation::inconclusive('abn');
        }

        $status = $data['AbnStatus'] ?? null;

        if (! is_string($status) || $status === '') {
            return VatIdValidation::inconclusive('abn');
        }

        if (strtolower($status) !== 'active') {
            return VatIdValidation::invalid('abn');
        }

        return VatIdValidation::valid(
            source: 'abn',
            name: $this->string($data, 'EntityName'),
            consultationReference: $this->string($data, 'Abn'),
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
