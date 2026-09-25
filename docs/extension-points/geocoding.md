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
  county names, ZIP+4 and coordinates described below). Geocodio offers no sales-tax
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

## Address-level resolution

Enable `tax.geocodio.rooftop` (`GEOCODIO_ROOFTOP=true`) and sync the boundary
artifacts to attach ZIP+4 or point localities. A `Jurisdiction` carries one locality,
chosen for the state's resolution path. The path is read from the installed register,
not from a list in the package, so a state that gains polygons or a county-level
answer in a new release is picked up by the next `tax:data:sync`:

| State (release 293) | Locality attached | Resolved by |
| --- | --- | --- |
| a state the register publishes polygons for — California, New Mexico, Texas | a **point**, scheme `latlng` (`34.052200,-118.243700`) | the register's polygon layer, read from each state's own GIS |
| the 24 Streamlined states | a **ZIP+4**, scheme `zip9` (`66101-3064`) | the register's boundary index, via `RegisterBoundaries` |
| a state whose `resolution` says `county` — Florida, Hawaii, Pennsylvania, Virginia | county or independent-city name, scheme `county` | register jurisdiction names; works with rooftop disabled |
| other states | none | state share, flagged where local tax may be missing |

A county name matches however the register writes it: "Honolulu County" finds "City
and County of Honolulu", and "Hawaii County" finds "County of Hawaii", never the state
of Hawaii. A named unit matches only the same unit, because Virginia has four names
that are both a county and an independent city: "Richmond County" is not "Richmond
City". A name that matches two jurisdictions is refused, not guessed.

Where the register lists the county-equivalents it prices at nothing local
(`unpriced`, from release 303), a listed name — "Roanoke city" — is the state share,
certain. A name found neither among the priced places nor in that list stays the
state share, flagged: an unmatched name is not knowledge.

Coordinates come back on every Geocodio result; the ZIP+4 needs the `zip4` append,
which the adapter requests when rooftop is on:

| Field | Example (701 N 7th St, Kansas City KS) |
| --- | --- |
| `fields.zip4.zip9` | `["66101-3064"]` — the USPS add-on |
| `address_components.postal_code` | `66101` — the ZIP5 alone, not enough |

A ZIP+4 is a **postal** key, not a taxing authority. The register's boundary index
expands it into the authorities that apply, and the rate source sums them — see
[register coverage](../coverage/the-register.md).
A point needs no such expansion: it is real geography, and the polygon it falls in
identifies the authorities whose rates the register supplies.

`tax:data:sync --streets=KS,WA` additionally installs street indexes. It does not
enable the geocoder's rooftop option; the shipped geocoder emits the locality keys
in the table. Parsed street addresses can be resolved through
`RegisterBoundaries::resolveParsed()`.

Two refusals worth knowing. Geocodio returns `zip9` as a **list**; an address
spanning several add-ons could straddle a jurisdiction line, so no locality is
attached rather than one being picked. And ZIP5 alone is never used: 54% of
Washington's ZIP5s and 91% of Kansas' span more than one jurisdiction set.

An earlier version attached a county FIPS from the `census` append instead. It could
never resolve — it names one authority where several may apply, and Geocodio's code
is state-prefixed where the dataset's are not.

Without a key the contract is left unbound. Bind your own `AddressGeocoder` if you
use a different provider.
