<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Rate source
    |--------------------------------------------------------------------------
    |
    | Out of the box the engine binds a StaticTaxRateSource with representative
    | national standard rates. To use live rate data (an EU TEDB adapter, the SST
    | files, or a commercial adapter), bind your own implementation of
    | Cbox\Tax\Contracts\TaxRateSource in a service provider — the engine owns the
    | calculation logic; only the rate DATA is sourced.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | US tax dataset (us-tax-data)
    |--------------------------------------------------------------------------
    |
    | The authoritative US source: the compiled us-tax-data dataset (schemaVersion
    | 4) supplying all-51 state rates, product taxability (25 categories), economic
    | nexus, and intrastate sourcing. Enabled by default — it REPLACES the hardcoded
    | US entries in the static tables: the dataset-backed sources are bound for the
    | US and composed ahead of the static snapshot (which now carries non-US only).
    |
    | `location` is an http(s) base URL (the public dataset mirror) or a local
    | directory, under which the split files live at `by-section/<section>.json`.
    | Only the small baseline/taxability/nexus/sourcing sections are fetched for the
    | common state-level path; the bulky `rates` section is read lazily and only
    | when a rooftop locality is resolved. Fetched sections are cached for `ttl`
    | seconds. Point `location` at a pinned tag or a committed local copy for an
    | offline/deterministic build; disable it to fall back to the static snapshot.
    |
    | `rooftop` (experimental, off by default) lets the Geocodio adapter capture the
    | address's ZIP+4 as a locality. The dataset's per-state boundary index expands
    | it into the taxing authorities that apply there, and EVERY applicable local
    | record is summed onto the state share — inside Kansas City a county and a city
    | record both apply (6.5% + 1.0% + 1.625%), inside Seattle only the city one
    | does (6.5% + 4.05%).
    |
    | It stays off by default because the boundary indexes are not published in the
    | dataset yet: the lookup 404s and the state rate applies. Once shipped it covers
    | the 24 SST member states — TX, CA, AZ, CO, LA, MO, NM, IL, AL and AK publish no
    | boundary files at all. See docs/coverage/us-tax-dataset.md.
    |
    */

    'us_tax_data' => [
        'enabled' => env('TAX_US_DATASET', true),
        'location' => env('TAX_US_DATASET_LOCATION', 'https://raw.githubusercontent.com/cboxdk/us-tax-dataset/main'),
        'ttl' => (int) env('TAX_US_DATASET_TTL', 86400),
        'rooftop' => env('TAX_US_DATASET_ROOFTOP', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | EU VAT live rate feed (optional)
    |--------------------------------------------------------------------------
    |
    | A real, public, MIT-licensed feed of EU member-state VAT rates: the
    | community-maintained ibericode/vat-rates dataset. Enable it to compose
    | ChainTaxRateSource(EU feed -> static snapshot): the feed is authoritative,
    | the shipped static rates are the fallback. Disabled by default, so the static
    | snapshot stays the zero-config default. The URL is config-driven (point it at
    | a pinned/mirrored copy if you prefer); a URL source is wrapped in the cache
    | automatically. See docs/coverage/eu-vat-feed.md for the source + license.
    |
    */

    'eu_vat' => [
        'enabled' => env('TAX_EU_VAT_FEED', false),
        'url' => env('TAX_EU_VAT_URL', 'https://raw.githubusercontent.com/ibericode/vat-rates/master/vat-rates.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | EU TEDB rate feed (optional)
    |--------------------------------------------------------------------------
    |
    | `live` calls the EU Commission's Taxes in Europe Database DIRECTLY — its
    | VatRetrievalService SOAP endpoint, which needs no API key and no
    | registration. This is the way to consume TEDB: the Commission publishes no
    | downloadable export, so the service and the web UI are the only sources.
    | Enabled, the engine composes ChainTaxRateSource(TEDB -> static snapshot).
    | Responses are cached per country for `ttl` seconds, so it costs one request
    | per country per TTL rather than one per assessment.
    |
    | It supplies every member state's STANDARD rate, plus a reduced band for the
    | few categories TEDB resolves to a single rate for that country. Where TEDB
    | carries a category at several rates at once — French pharmaceuticals sit at
    | 2.1%, 5.5% and 10% — the band is refused and the standard rate applies, so a
    | supply is never quietly charged the wrong reduced rate.
    |
    | `url` is the older, file-based path: a TEDB-derived dataset you generated
    | yourself, in the JSON shape documented on Cbox\Tax\RateSource\TedbRateSource,
    | as an http(s) URL or local file. Use it to pin a reviewed snapshot instead of
    | calling the service. Both empty (the default) runs purely on the static
    | snapshot.
    |
    */

    'tedb' => [
        'live' => env('TAX_TEDB_LIVE', false),
        'ttl' => (int) env('TAX_TEDB_TTL', 86400),
        'url' => env('TAX_TEDB_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Address geocoder (Geocodio)
    |--------------------------------------------------------------------------
    |
    | Optional but recommended for US/Canada, where a state/province (or, for the
    | US, a rooftop address) is needed to resolve the taxing jurisdiction. Set a
    | Geocodio API key to bind Cbox\Tax\Contracts\AddressGeocoder to the Geocodio
    | adapter. Without a key the contract is left unbound (deny-by-default) — bind
    | your own if you use a different provider.
    |
    | `base_url` targets Geocodio API v2. The adapter reads both v2's
    | `state_province` and v1's `state` key, so pinning this back to a v1.x URL
    | still resolves.
    |
    */

    'geocodio' => [
        'key' => env('GEOCODIO_API_KEY'),
        'base_url' => env('GEOCODIO_BASE_URL', 'https://api.geocod.io/v2'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax-ID validation
    |--------------------------------------------------------------------------
    |
    | The VatIdValidator is bound to VIES (EU) + HMRC (UK) out of the box. To also
    | validate Australian ABNs, set an ABN Lookup GUID; without it, AU lookups
    | return inconclusive (and callers fall back to charging tax).
    |
    */

    'vat_id' => [
        'abn_guid' => env('ABN_LOOKUP_GUID'),
    ],

];
