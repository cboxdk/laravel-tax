<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The register (data.cboxtax.com)
    |--------------------------------------------------------------------------
    |
    | Where the rate data comes from. The register covers 80 jurisdictions across
    | eleven regimes — the EU, the US, the UK and the rest of Europe, Canada,
    | Mexico, Latin America, Asia-Pacific, Africa, the Caribbean, the Gulf and the
    | wider Middle East.
    |
    | IT IS NOT FETCHED WHILE PRICING. `php artisan tax:data:sync` compiles a
    | published release into a local store; the engine reads that store and makes
    | no network call at all. Two reasons, and both are load-bearing. The register
    | publishes several times a day, so a rate fetched per request can move under a
    | half-priced order — which version is live has to be a decision somebody makes.
    | And the US region alone is 48.8 MB of JSON, which `json_decode` turns into
    | 315 MB of PHP arrays: it does not blow a generous memory limit, it blows the
    | default one.
    |
    | A FRESH INSTALL HAS NOTHING AND SAYS SO. No rate data ships inside this
    | package — it is MIT and the register is PolyForm Internal Use, and bundling
    | one inside the other would mislabel it. Until `tax:data:sync` has run the
    | engine REFUSES rather than guessing, and the refusal names the command. Add
    | it to your deploy alongside `php artisan migrate`.
    |
    | `store` is where the compiled register lives; null puts it under
    | storage/app/cbox-tax/register. `version` pins pricing and sync, or null follows
    | whatever the last sync made active. A missing pin refuses; it never falls back.
    | Lists accept arrays or comma-separated env values. Command-line options
    | override sync defaults. `keep` retains the newest releases after each sync;
    | the active release and configured pin are always preserved. `boundaries`
    | includes postal/polygon indexes, and `streets` opts states into street indexes.
    |
    | OPT IN TO LESS, NOT MORE. `regions` and `states` narrow what gets compiled,
    | and narrowing is safe precisely because a jurisdiction outside what you
    | compiled REFUSES instead of answering from an absence. The weight is almost
    | entirely American: all ten non-US regimes together are 4 MB, the US is 38 MB
    | of rates, and Washington alone is 13.4 MB of that. A shop selling only into
    | Texas wants `states => ['TX']`.
    |
    | LICENCE — read this before you build a product on it. This package is MIT;
    | the register it compiles is NOT. It is published under the PolyForm Internal
    | Use Licence 1.0.0: use it inside your own organisation for anything,
    | including commercially — pricing your own sales is exactly the intended use.
    | What it does not permit is passing the data on: redistributing it, bundling
    | it into something you ship, or offering your customers a rate lookup, an API
    | or a feature that gives them the rates themselves. The line is "charging your
    | customers tax you computed with it" (fine) against "telling your customers
    | what the rates are" (not covered). The second needs a licence from Cbox, and
    | it is a thing you buy when you cross the line rather than a gate on getting
    | started.
    |
    */

    'register' => [
        'url' => env('TAX_REGISTER_URL', 'https://data.cboxtax.com'),
        'store' => env('TAX_REGISTER_STORE'),
        'version' => env('TAX_REGISTER_VERSION'),
        'regions' => env('TAX_REGISTER_REGIONS'),
        'states' => env('TAX_REGISTER_STATES'),
        'streets' => env('TAX_REGISTER_STREETS'),
        'boundaries' => env('TAX_REGISTER_BOUNDARIES', true),
        'keep' => (int) env('TAX_REGISTER_KEEP', 2),
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
    | still resolves. `rooftop` enables the ZIP+4 append and point localities;
    | county resolution works without it. TAX_US_DATASET_ROOFTOP remains an env
    | fallback for upgrades; use GEOCODIO_ROOFTOP in new configurations.
    |
    */

    'geocodio' => [
        'key' => env('GEOCODIO_API_KEY'),
        'base_url' => env('GEOCODIO_BASE_URL', 'https://api.geocod.io/v2'),
        'rooftop' => env('GEOCODIO_ROOFTOP', env('TAX_US_DATASET_ROOFTOP', false)),
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
