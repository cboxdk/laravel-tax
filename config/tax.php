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
