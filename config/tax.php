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
    | 3) supplying all-51 state rates, product taxability (25 categories), economic
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
    | `rooftop` (experimental, off by default) lets the Geocodio adapter capture a
    | county FIPS as a locality so the rate source stacks a local rate. It is
    | PARTIAL — the dataset's per-state local codes are heterogeneous and county
    | FIPS cannot pick city/special-district records — so it is opt-in until a
    | point→jurisdiction crosswalk lands. Absent a locality, the state rate applies.
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
    | Point this at a TEDB-derived dataset — the EU Commission's Taxes in Europe
    | Database (VatRetrievalService), transformed to the JSON shape documented on
    | Cbox\Tax\RateSource\TedbRateSource — as either an http(s) URL or a local file
    | path. When set, the engine composes ChainTaxRateSource(TEDB -> static
    | snapshot): TEDB is authoritative, the shipped static rates are the fallback.
    | Leave it empty (the default) to run purely on the static snapshot. The
    | package ships NO endpoint — you must supply a real TEDB export. For a URL
    | source, wrap the binding in CachingTaxRateSource to avoid a request per lookup
    | (see docs/extension-points/rate-sources.md).
    |
    */

    'tedb' => [
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
    */

    'geocodio' => [
        'key' => env('GEOCODIO_API_KEY'),
        'base_url' => env('GEOCODIO_BASE_URL', 'https://api.geocod.io/v1.7'),
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
