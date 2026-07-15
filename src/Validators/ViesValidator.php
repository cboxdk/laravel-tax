<?php

declare(strict_types=1);

namespace Cbox\Tax\Validators;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Tax\ValueObjects\VatIdValidation;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Validates an EU VAT number against the Commission's VIES service (REST). Returns
 * an inconclusive result on any transport failure — VIES has a reputation for
 * intermittent downtime, and a failure must never be mistaken for "valid".
 */
readonly class ViesValidator implements VatIdValidator
{
    /** @var list<string> */
    private const EU = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
        'SI', 'ES', 'SE',
    ];

    public function __construct(
        private Factory $http,
        private string $baseUrl = 'https://ec.europa.eu/taxation_customs/vies/rest-api',
    ) {}

    public function supports(CountryCode $country): bool
    {
        return in_array($country->value, self::EU, true);
    }

    public function validate(CountryCode $country, string $taxId): VatIdValidation
    {
        // VIES uses the "EL" prefix for Greece, not the ISO "GR".
        $viesCountry = $country->value === 'GR' ? 'EL' : $country->value;
        $number = $this->normalize($viesCountry, $taxId);

        try {
            $response = $this->http->asJson()->post($this->baseUrl.'/check-vat-number', [
                'countryCode' => $viesCountry,
                'vatNumber' => $number,
            ]);
        } catch (Throwable) {
            return VatIdValidation::inconclusive('vies');
        }

        if (! $response->successful()) {
            return VatIdValidation::inconclusive('vies');
        }

        $data = $response->json();

        if (! is_array($data) || ! array_key_exists('valid', $data)) {
            return VatIdValidation::inconclusive('vies');
        }

        if ($data['valid'] !== true) {
            return VatIdValidation::invalid('vies');
        }

        return VatIdValidation::valid(
            source: 'vies',
            name: $this->string($data, 'name'),
            address: $this->string($data, 'address'),
            consultationReference: $this->string($data, 'requestIdentifier'),
        );
    }

    private function normalize(string $viesCountry, string $taxId): string
    {
        $id = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $taxId));

        if (str_starts_with($id, $viesCountry)) {
            $id = substr($id, strlen($viesCountry));
        }

        return $id;
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
