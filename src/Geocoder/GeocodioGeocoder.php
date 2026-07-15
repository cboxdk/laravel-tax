<?php

declare(strict_types=1);

namespace Cbox\Tax\Geocoder;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\AddressGeocoder;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;

/**
 * Resolves an address to a jurisdiction via the Geocodio API (US + Canada). It
 * takes only the geocoding/jurisdiction resolution from Geocodio — country and
 * state/province — and hands off to the rate engine; the rate and calculation
 * stay owned by this package.
 *
 * Deny-by-default: any failure (no key match, request error, unparseable result,
 * a state that does not resolve in the geo reference) returns `null`, so the
 * caller refuses rather than guessing.
 */
readonly class GeocodioGeocoder implements AddressGeocoder
{
    public function __construct(
        private Factory $http,
        private JurisdictionRepository $geo,
        private string $apiKey,
        private string $baseUrl = 'https://api.geocod.io/v1.7',
    ) {}

    public function locate(array $address): ?Jurisdiction
    {
        $query = $this->singleLine($address);

        if ($query === '') {
            return null;
        }

        $response = $this->http->get($this->baseUrl.'/geocode', [
            'q' => $query,
            'api_key' => $this->apiKey,
            'limit' => 1,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $components = $this->firstComponents($response->json('results'));

        if ($components === null) {
            return null;
        }

        $countryValue = $components['country'] ?? null;
        $stateValue = $components['state'] ?? null;

        if (! is_string($countryValue) || ! is_string($stateValue)) {
            return null;
        }

        try {
            $country = new CountryCode($countryValue);
            $subdivision = new SubdivisionCode($country->value.'-'.$stateValue);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $this->geo->find($country, $subdivision);
    }

    /**
     * @param  array<string, string|null>  $address
     */
    private function singleLine(array $address): string
    {
        $parts = [];

        foreach (['line1', 'city', 'subdivision', 'postalCode', 'country'] as $key) {
            $value = $address[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function firstComponents(mixed $results): ?array
    {
        if (! is_array($results) || $results === []) {
            return null;
        }

        $first = $results[array_key_first($results)];

        if (! is_array($first)) {
            return null;
        }

        $components = $first['address_components'] ?? null;

        return is_array($components) ? $components : null;
    }
}
