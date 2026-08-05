---
title: Address geocoding
weight: 2
description: Resolve US/CA addresses to a jurisdiction with the Geocodio adapter, or bind your own.
---

# Address geocoding

US sales tax stacks rates below the state (county, city, special district), so a
state alone is not enough — the address must be resolved to a taxing jurisdiction.
The `AddressGeocoder` contract is that seam.

The shipped **`GeocodioGeocoder`** resolves US and Canada addresses via Geocodio
**API v2**. Set an API key to bind it:

```php
// config/tax.php  (or .env: GEOCODIO_API_KEY=...)
'geocodio' => [
    'key' => env('GEOCODIO_API_KEY'),
],
```

```php
use Cbox\Tax\Contracts\AddressGeocoder;

$jurisdiction = app(AddressGeocoder::class)->locate([
    'line1' => '1600 Amphitheatre Pkwy',
    'city' => 'Mountain View',
    'subdivision' => 'CA',
    'postalCode' => '94043',
    'country' => 'US',
]);
// -> resolved Cbox\Geo Jurisdiction (US-CA), or null
```

Two rules the design keeps:

- **We take only geocoding from Geocodio** — country and state/province (plus the
  census identifiers below when rooftop is enabled). Geocodio offers no sales-tax
  or taxing-jurisdiction append, and none is wanted: the rate and the calculation
  stay in this engine, so it remains authoritative and the adapter swappable.
- **Deny-by-default.** Any failure — no key, request error, unparseable result, a
  state that does not resolve — returns `null`. Never a ZIP-centroid guess.

### API version

The adapter targets **v2** (`https://api.geocod.io/v2`). Of v2's breaking changes
only two touch it — the response no longer carries a top-level `input` key beside
`results`, and `address_components.state` became `state_province` — and both key
spellings are read, so passing a v1.x `baseUrl` to the constructor still resolves:

```php
new GeocodioGeocoder($http, $geo, $key, 'https://api.geocod.io/v1.7');
```

Two further v2 changes do not affect this adapter: `zip` became `postal_code`
(unread), and Canadian results now echo the full postal code where the FSA matches
instead of returning the FSA alone. The `census` append is unchanged between
versions.

## Rooftop resolution (experimental)

With `us_tax_data.rooftop` enabled the adapter requests Geocodio's `census` field
append and attaches the **county FIPS** to the jurisdiction as a `LocalityCode`
(scheme `county-fips`), so a rate source can try to stack a local rate. The census
append returns, per census year:

| Field | Example (400 Broad St, Seattle WA) |
| --- | --- |
| `state_fips` | `53` |
| `county_fips` | `53033` — state-prefixed |
| `place` | `{"name": "Seattle", "fips": "5363000"}` — state-prefixed |
| `tract_code` / `block_code` / `full_fips` | census geography, not taxing geography |

**This does not currently resolve a rooftop rate**, and the flag is off by default
for that reason — see
[the US dataset's rooftop section](../coverage/us-tax-dataset.md#rooftop-is-partial-and-opt-in)
for exactly what is missing. A census place is not a taxing jurisdiction: special
tax districts are polygons that no point identifier selects.

Without a key the contract is left unbound. Bind your own `AddressGeocoder` if you
use a different provider.
