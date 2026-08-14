<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Exceptions\RateSourceUnavailable;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Resolves a US rooftop rate by asking a state's OWN published map service which
 * taxing jurisdiction a point falls in.
 *
 * Two states publish rooftop geography this way rather than as a boundary file,
 * and both are unauthenticated:
 *
 *  - **California** — CDTFA's `California_Sales_and_Use_Tax_Rates` feature service.
 *    A point returns the jurisdiction and its all-in `RATE`.
 *  - **New Mexico** — the TRD gross-receipts service (the same one the compiled
 *    dataset reads for rates, queried WITH geometry rather than without). A point
 *    returns the location code and its combined `grt_rate`.
 *
 * This is a live query per location, cached — the posture of {@see TedbSoapRateSource},
 * not of the shipped boundary indexes. It is also FINER than those: a polygon is
 * real geography, where a ZIP+4 is a postal proxy for it.
 *
 * The rates these services return are ALL-IN for the point, so nothing is stacked
 * on top; the value is used as returned. A category-specific rate is not attempted
 * — neither service carries one — so a reduced category must come from another
 * source in the chain, and this one answers for the general rate only.
 *
 * Deny-by-default: no coordinates, an unserved state, an unreachable service, a
 * point in no polygon, or a malformed response all yield null, and a composed
 * {@see ChainTaxRateSource} falls through to the state rate.
 */
readonly class ArcGisRateSource implements TaxRateSource
{
    /**
     * The scheme a geocoder uses to carry a WGS84 point as a locality: the value is
     * `"<lat>,<lng>"`. A {@see Jurisdiction} carries exactly
     * one locality, so a geocoder emits this for the states served here and a
     * postal key elsewhere — the choice belongs with the knowledge of which states
     * publish polygons, which is this list.
     */
    public const string LATLNG_SCHEME = 'latlng';

    private const string SOURCE = 'state-gis';

    /**
     * Per state: the queryable layer, the field carrying the rate, whether that
     * rate is a fraction (0.0975) or a percentage (7.625), and the field naming the
     * jurisdiction. Values verified against both services on 2026-08-05.
     *
     * @var array<string, array{layer: string, rate: string, fraction: bool, name: string}>
     */
    private const SERVICES = [
        'US-CA' => [
            'layer' => 'https://services6.arcgis.com/snwvZ3EmaoXJiugR/arcgis/rest/services/California_Sales_and_Use_Tax_Rates/FeatureServer/0',
            'rate' => 'RATE',
            'fraction' => true,
            'name' => 'JURIS_NAME',
        ],
        'US-NM' => [
            'layer' => 'https://services5.arcgis.com/f4lpEvI6fkgVYigk/arcgis/rest/services/New_Mexico_gross_receipts_tax_Jan_July_2025_pub/FeatureServer/33',
            'rate' => 'grt_rate',
            'fraction' => false,
            'name' => 'locat_cdr',
        ],
    ];

    public function __construct(
        private Factory $http,
        private ?Repository $cache = null,
        private int $ttl = 86400,
    ) {}

    /**
     * The states this source can answer for — the list a geocoder consults to
     * decide whether a point or a postal key is the useful locality.
     *
     * @return list<string>
     */
    public static function states(): array
    {
        return array_keys(self::SERVICES);
    }

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        // These services answer for TODAY and carry no history — CDTFA's and New
        // Mexico's layers are the current rate schedule, not a dated one. Now that
        // the engine passes the supply date, silently returning today's rate for a
        // 2020 credit note would be the worst of both worlds: the caller asked a
        // dated question and got an undated answer stamped `Authoritative`. Refuse,
        // and let the chain fall through to a source that can answer.
        if ($at !== null && $at->format('Y-m-d') !== new DateTimeImmutable()->format('Y-m-d')) {
            return null;
        }

        $subdivision = $jurisdiction->subdivision?->value;
        $service = $subdivision === null ? null : (self::SERVICES[$subdivision] ?? null);
        $locality = $jurisdiction->locality;

        if ($service === null || $locality === null || $locality->scheme !== self::LATLNG_SCHEME) {
            return null;
        }

        $point = $this->point($locality->value);

        if ($point === null) {
            return null;
        }

        $percent = $this->lookup($subdivision, $service, $point);

        return $percent === null
            ? null
            : new TaxRate($percent, RateKind::Standard, self::SOURCE, Confidence::Authoritative);
    }

    /**
     * @param  array{layer: string, rate: string, fraction: bool, name: string}  $service
     * @param  array{0: float, 1: float}  $point
     */
    private function lookup(string $subdivision, array $service, array $point): ?string
    {
        [$lat, $lng] = $point;

        // Cached on the POINT, not the address: many addresses share a polygon, and
        // a rounded key keeps neighbouring rooftops from each costing a request.
        $key = sprintf('cbox-tax:gis:%s:%.5f,%.5f', $subdivision, $lat, $lng);
        $cached = $this->cache?->get($key);

        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        try {
            $response = $this->http->get($service['layer'].'/query', [
                'geometry' => json_encode(['x' => $lng, 'y' => $lat, 'spatialReference' => ['wkid' => 4326]], JSON_THROW_ON_ERROR),
                'geometryType' => 'esriGeometryPoint',
                'inSR' => 4326,
                'spatialRel' => 'esriSpatialRelIntersects',
                'outFields' => $service['rate'].','.$service['name'],
                'returnGeometry' => 'false',
                'f' => 'json',
            ]);
        } catch (Throwable $e) {
            // Reached only for the two states that publish polygons, so getting
            // here at all means this source was expected to answer.
            throw RateSourceUnavailable::transport('arcgis', $e->getMessage());
        }

        if (! $response->successful()) {
            throw RateSourceUnavailable::badResponse('arcgis', $response->status());
        }

        $percent = $this->percentFrom($response->json('features'), $service);

        // A point genuinely outside every polygon is cached as a miss, so an
        // address in the sea does not re-query on every assessment.
        $this->cache?->put($key, $percent ?? '', $this->ttl);

        return $percent;
    }

    /**
     * @param  array{layer: string, rate: string, fraction: bool, name: string}  $service
     */
    private function percentFrom(mixed $features, array $service): ?string
    {
        if (! is_array($features) || $features === []) {
            return null;
        }

        $first = $features[array_key_first($features)];
        $attributes = is_array($first) && is_array($first['attributes'] ?? null) ? $first['attributes'] : null;
        $rate = $attributes[$service['rate']] ?? null;

        if (! is_int($rate) && ! is_float($rate)) {
            return null;
        }

        // CDTFA publishes a fraction (0.0975), TRD a percentage (7.625).
        $percent = $service['fraction'] ? $rate * 100 : $rate;

        return rtrim(rtrim(number_format($percent, 5, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Parse a `"<lat>,<lng>"` locality into a WGS84 point, refusing anything that
     * is not a plausible coordinate rather than querying with nonsense.
     *
     * @return array{0: float, 1: float}|null
     */
    private function point(string $value): ?array
    {
        $parts = explode(',', $value);

        if (count($parts) !== 2 || ! is_numeric(trim($parts[0])) || ! is_numeric(trim($parts[1]))) {
            return null;
        }

        $lat = (float) trim($parts[0]);
        $lng = (float) trim($parts[1]);

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return [$lat, $lng];
    }
}
