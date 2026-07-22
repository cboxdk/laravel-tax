<?php

declare(strict_types=1);

namespace Cbox\Tax\Geocoder;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
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
 * When `$rooftop` is enabled it also requests Geocodio's census fields and, for US
 * results, attaches the county FIPS as a {@see LocalityCode} (scheme `county-fips`)
 * so a rate source can stack a local rate. This is PARTIAL and experimental: a
 * county FIPS cannot pick city or special-district records, so it is off by default
 * and left to the caller to enable knowingly. Absent a locality the state rate
 * applies.
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
        private bool $rooftop = false,
    ) {}

    public function locate(array $address): ?Jurisdiction
    {
        $query = $this->singleLine($address);

        if ($query === '') {
            return null;
        }

        $params = [
            'q' => $query,
            'api_key' => $this->apiKey,
            'limit' => 1,
        ];

        // Rooftop resolution needs the county FIPS, which Geocodio returns in its
        // census append fields.
        if ($this->rooftop) {
            $params['fields'] = 'census';
        }

        $response = $this->http->get($this->baseUrl.'/geocode', $params);

        if (! $response->successful()) {
            return null;
        }

        $first = $this->firstResult($response->json('results'));

        if ($first === null) {
            return null;
        }

        $components = is_array($first['address_components'] ?? null) ? $first['address_components'] : null;
        $countryValue = is_array($components) ? ($components['country'] ?? null) : null;
        $stateValue = is_array($components) ? ($components['state'] ?? null) : null;

        if (! is_string($countryValue) || ! is_string($stateValue)) {
            return null;
        }

        try {
            $country = new CountryCode($countryValue);
            $subdivision = new SubdivisionCode($country->value.'-'.$stateValue);
        } catch (InvalidArgumentException) {
            return null;
        }

        $jurisdiction = $this->geo->find($country, $subdivision);

        if ($jurisdiction === null || ! $this->rooftop || $country->value !== 'US') {
            return $jurisdiction;
        }

        $locality = $this->localityFrom($first, $subdivision);

        return $locality === null ? $jurisdiction : $jurisdiction->withLocality($locality);
    }

    /**
     * Extract a county-FIPS locality from a Geocodio result's census fields, if
     * present and well-formed. Returns null otherwise (the caller keeps the plain
     * state-level jurisdiction).
     *
     * @param  array<array-key, mixed>  $result
     */
    private function localityFrom(array $result, SubdivisionCode $subdivision): ?LocalityCode
    {
        $fields = $result['fields'] ?? null;
        $census = is_array($fields) && is_array($fields['census'] ?? null) ? $fields['census'] : null;

        if ($census === null || $census === []) {
            return null;
        }

        // Census is keyed by year; take the most recent entry.
        $latest = $census[array_key_last($census)];

        if (! is_array($latest)) {
            return null;
        }

        $countyFips = $latest['county_fips'] ?? null;

        if (! is_string($countyFips) || $countyFips === '') {
            return null;
        }

        $countyName = is_string($latest['county_name'] ?? null) ? $latest['county_name'] : null;

        return new LocalityCode($subdivision, 'county-fips', $countyFips, $countyName);
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
    private function firstResult(mixed $results): ?array
    {
        if (! is_array($results) || $results === []) {
            return null;
        }

        $first = $results[array_key_first($results)];

        return is_array($first) ? $first : null;
    }
}
