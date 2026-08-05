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

A failure is **retried once** before it is believed. Geocodio answers
`403 Invalid API key` intermittently on a valid key (observed twice in roughly ten
calls while this adapter was built), and with rooftop enabled an unresolved address
is not a degraded rate but a failed assessment — `JurisdictionNotResolved`. A
genuinely invalid key just costs one extra request.

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

A `Jurisdiction` carries exactly **one** locality, so with `us_tax_data.rooftop`
enabled the adapter attaches whichever key that state is actually resolved by:

| State | Locality attached | Resolved by |
| --- | --- | --- |
| California, New Mexico | a **point**, scheme `latlng` (`34.052200,-118.243700`) | the state's own polygon service, via `ArcGisRateSource` |
| the 24 Streamlined states | a **ZIP+4**, scheme `zip9` (`66101-3064`) | the dataset's boundary index, via `UsTaxDatasetRateSource` |
| everywhere else | none | the state rate applies |

Coordinates come back on every Geocodio result; the ZIP+4 needs the `zip4` append,
which the adapter requests when rooftop is on:

| Field | Example (701 N 7th St, Kansas City KS) |
| --- | --- |
| `fields.zip4.zip9` | `["66101-3064"]` — the USPS add-on |
| `address_components.postal_code` | `66101` — the ZIP5 alone, not enough |

A ZIP+4 is a **postal** key, not a taxing authority. The dataset's boundary index
expands it into the authorities that apply, and the rate source sums them — see
[the US dataset's rooftop section](../coverage/us-tax-dataset.md#rooftop-zip4-into-the-boundary-index).
A point needs no such expansion: it is real geography, and the polygon it falls in
carries the rate directly.

Two refusals worth knowing. Geocodio returns `zip9` as a **list**; an address
spanning several add-ons could straddle a jurisdiction line, so no locality is
attached rather than one being picked. And ZIP5 alone is never used: 54% of
Washington's ZIP5s and 91% of Kansas' span more than one jurisdiction set.

An earlier version attached a county FIPS from the `census` append instead. It could
never resolve — it names one authority where several may apply, and Geocodio's code
is state-prefixed where the dataset's are not.

Without a key the contract is left unbound. Bind your own `AddressGeocoder` if you
use a different provider.
