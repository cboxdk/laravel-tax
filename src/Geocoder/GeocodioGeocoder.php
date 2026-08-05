<?php

declare(strict_types=1);

namespace Cbox\Tax\Geocoder;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\AddressGeocoder;
use Cbox\Tax\RateSource\ArcGisRateSource;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use Throwable;

/**
 * Resolves an address to a jurisdiction via the Geocodio API (US + Canada). It
 * takes only the geocoding/jurisdiction resolution from Geocodio — country and
 * state/province — and hands off to the rate engine; the rate and calculation
 * stay owned by this package.
 *
 * Targets **API v2**. Of v2's breaking changes only two touch this adapter: the
 * response no longer wraps results beside a top-level `input` key (we always read
 * `results`), and `address_components.state` became `state_province`. Both keys are
 * accepted so pinning `baseUrl` to a v1.x URL still resolves. Canadian results now
 * echo the full postal code where the FSA matches, which this adapter does not read.
 *
 * When `$rooftop` is enabled it also requests Geocodio's `zip4` append and, for US
 * results, attaches the full ZIP+4 as a {@see LocalityCode} (scheme
 * {@see UsTaxDatasetRateSource::ZIP9_SCHEME}, e.g. `66101-3064`).
 *
 * A ZIP+4 is a POSTAL key, not a taxing authority — it is what the dataset's
 * boundary index is keyed by, and that index is what turns it into the authorities
 * that actually apply (a county and a city in Kansas City, the city alone in
 * Seattle). An earlier version attached a county FIPS instead, which could never
 * resolve: it named one authority where several may apply, and Geocodio's is
 * state-prefixed where the dataset's codes are not.
 *
 * Deny-by-default: any failure (no key match, request error, unparseable result,
 * a state that does not resolve in the geo reference) returns `null`, so the
 * caller refuses rather than guessing.
 */
readonly class GeocodioGeocoder implements AddressGeocoder
{
    /**
     * Geocodio answers `403 Invalid API key` intermittently on a perfectly valid
     * key — observed twice in roughly ten calls while this adapter was being built.
     * That used to cost a state-level rate; with rooftop resolution it costs the
     * whole assessment, because an address that does not resolve raises
     * `JurisdictionNotResolved`. So a failure is retried once before it is believed.
     *
     * A genuinely invalid key just costs one extra request.
     */
    private const int ATTEMPTS = 2;

    private const int RETRY_DELAY_MS = 250;

    public function __construct(
        private Factory $http,
        private JurisdictionRepository $geo,
        private string $apiKey,
        private string $baseUrl = 'https://api.geocod.io/v2',
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

        // Rooftop resolution is keyed by ZIP+4 — the USPS add-on from Geocodio's
        // zip4 append, not the postal code on address_components. California and
        // New Mexico are resolved by coordinates instead (see localityFrom), and
        // those come back on every result without an append.
        if ($this->rooftop) {
            $params['fields'] = 'zip4';
        }

        try {
            $response = $this->http
                ->retry(self::ATTEMPTS, self::RETRY_DELAY_MS, throw: false)
                ->get($this->baseUrl.'/geocode', $params);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $first = $this->firstResult($response->json('results'));

        if ($first === null) {
            return null;
        }

        $components = is_array($first['address_components'] ?? null) ? $first['address_components'] : null;
        $countryValue = is_array($components) ? ($components['country'] ?? null) : null;

        // v2 renamed `state` to `state_province`; the older key is still read so an
        // operator who pins `baseUrl` to v1.x keeps working.
        $stateValue = is_array($components)
            ? ($components['state_province'] ?? $components['state'] ?? null)
            : null;

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
     * Extract a ZIP+4 locality from a Geocodio result's `zip4` append. Returns null
     * when the address carries no add-on — the caller then keeps the plain
     * state-level jurisdiction and the state rate applies, rather than a rooftop
     * rate resolved from a partial key.
     *
     * @param  array<array-key, mixed>  $result
     */
    private function localityFrom(array $result, SubdivisionCode $subdivision): ?LocalityCode
    {
        // A jurisdiction carries ONE locality, so the useful key differs by state:
        // California and New Mexico publish polygon services a point resolves
        // against, which is finer than the postal proxy the ZIP+4 index offers.
        if (in_array($subdivision->value, ArcGisRateSource::states(), true)) {
            return $this->pointLocality($result, $subdivision);
        }

        $fields = $result['fields'] ?? null;
        $zip4 = is_array($fields) && is_array($fields['zip4'] ?? null) ? $fields['zip4'] : null;
        $zip9 = is_array($zip4) && is_array($zip4['zip9'] ?? null) ? $zip4['zip9'] : null;

        // Geocodio returns zip9 as a LIST: an address spanning several add-ons is
        // ambiguous, and picking one would silently choose a jurisdiction.
        if ($zip9 === null || count($zip9) !== 1) {
            return null;
        }

        $value = reset($zip9);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return new LocalityCode($subdivision, UsTaxDatasetRateSource::ZIP9_SCHEME, $value);
    }

    /**
     * A WGS84 point locality from the result's `location`, for the states resolved
     * by polygon rather than by postal key. Null when the geocoder returned no
     * usable coordinates — the caller then keeps the state-level jurisdiction.
     *
     * @param  array<array-key, mixed>  $result
     */
    private function pointLocality(array $result, SubdivisionCode $subdivision): ?LocalityCode
    {
        $location = is_array($result['location'] ?? null) ? $result['location'] : null;
        $lat = $location['lat'] ?? null;
        $lng = $location['lng'] ?? null;

        if ((! is_float($lat) && ! is_int($lat)) || (! is_float($lng) && ! is_int($lng))) {
            return null;
        }

        return new LocalityCode(
            $subdivision,
            ArcGisRateSource::LATLNG_SCHEME,
            sprintf('%.6F,%.6F', $lat, $lng),
        );
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
