---
title: Address geocoding
weight: 2
description: Resolve US/CA addresses to a jurisdiction with the Geocodio adapter, or bind your own.
---

# Address geocoding

US sales tax stacks rates below the state (county, city, special district), so a
state alone is not enough — the address must be resolved to a taxing jurisdiction.
The `AddressGeocoder` contract is that seam.

The shipped **`GeocodioGeocoder`** resolves US and Canada addresses via Geocodio.
Set an API key to bind it:

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

- **We take only geocoding from Geocodio** — country and state/province. The rate
  and the calculation stay in this engine; Geocodio's own tax append is not used,
  so the engine stays authoritative and the adapter swappable.
- **Deny-by-default.** Any failure — no key, request error, unparseable result, a
  state that does not resolve — returns `null`. Never a ZIP-centroid guess.

Without a key the contract is left unbound. Bind your own `AddressGeocoder` if you
use a different provider.
