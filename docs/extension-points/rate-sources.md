---
title: Rate sources
weight: 1
description: Bind a live TaxRateSource — TEDB, SST or a commercial adapter.
---

# Rate sources

The engine owns the calculation; the **rate number** is the one thing it sources.
Bind your own `Contracts\TaxRateSource` to replace the default static rates:

```php
use Cbox\Tax\Contracts\TaxRateSource;

$this->app->singleton(TaxRateSource::class, fn () => new TedbRateSource(/* ... */));
```

A source returns a `TaxRate` (percentage, band, provenance, confidence) for a
jurisdiction and category, or `null` when it has no rate — in which case the
engine raises `UnresolvedTaxRate` rather than assuming 0%.

Recommended defaults per region:

| Region | Source |
| --- | --- |
| EU | the EU Commission's TEDB rate feed |
| US (SST states) | the SST Rate & Boundary files |
| US (non-SST / home-rule), Canada provinces | a commercial adapter |

Rates are **data that changes** — treat them as versioned/refreshable, never
hard-coded. Record the `source` and `confidence` on each assessment so a coarse
fallback is never mistaken for an authoritative rate.

## Category-aware rates (reduced / zero bands)

`rateFor()` receives the supply's **`TaxCategory`**, and the shipped sources honour
it: a source may carry per-(jurisdiction, category) **reduced or zero bands** and
return one instead of the standard rate. Pass bands to `StaticTaxRateSource` keyed
by `"<jurisdiction>:<category>"`:

```php
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\ValueObjects\RateBand;

new StaticTaxRateSource(rates: null, bands: [
    'FR:digital_service' => new RateBand('5.5', RateKind::Reduced),
    'DK:digital_service' => new RateBand('0', RateKind::Zero),
]);
```

> **No national reduced-rate table ships.** The default snapshot carries **only
> standard rates** — the package will not fabricate reduced bands, which are DATA
> that must come from an authoritative feed. Supply your own bands, or bind a TEDB
> export whose entries carry a `bands` map. A category with no band resolves the
> standard rate.

## The TEDB adapter

`TedbRateSource` reads a **TEDB-derived dataset** — the EU Commission's *Taxes in
Europe Database* (`VatRetrievalService`), transformed to the JSON shape below. Its
location is **config-driven** (`tax.tedb.url`), an `http(s)` URL **or** a local file
path; the package ships **no endpoint**, so you must point it at a real export.

Set `tax.tedb.url` (env `TAX_TEDB_URL`) and the provider composes
`ChainTaxRateSource(TEDB → static snapshot)` automatically — TEDB is authoritative,
the static snapshot is the fallback. Unconfigured, the plain static snapshot stays
the zero-config default.

Documented dataset shape:

```json
{
  "version": "2026-07-01",
  "rates": {
    "DK": { "standard": "25" },
    "FR": { "standard": "20", "bands": { "digital_service": { "rate": "5.5", "kind": "reduced" } } }
  }
}
```

Each country entry's `standard` is the standard rate; an optional `bands` map keys
reduced/zero rates by `TaxCategory` value (`kind` ∈ `reduced` | `zero`). A missing
country, an unreadable source, or malformed JSON yields `null` so the engine denies
(and the chain falls back to the static snapshot) rather than guessing. For a URL
source, wrap it in `CachingTaxRateSource` to avoid a request per lookup.

## Composing sources

The package ships composable sources so you can assemble a live feed with a safe
fallback:

- **`StaticTaxRateSource`** — the built-in map (default binding); accepts optional
  reduced/zero `bands`.
- **`TedbRateSource`** — reads a TEDB-derived dataset (URL or file); auto-wired to a
  `ChainTaxRateSource` fallback when `tax.tedb.url` is set.
- **`RemoteRateSource`** — fetches a generic JSON country→rate feed (number,
  `{standard}`, or `{standard, bands}`); one request per lookup, so wrap it in caching.
- **`CachingTaxRateSource`** — caches the current rate from an inner source; a
  date-specific lookup bypasses the cache.
- **`ChainTaxRateSource`** — tries sources in order, first hit wins.

```php
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\RateSource\{ChainTaxRateSource, CachingTaxRateSource, TedbRateSource, StaticTaxRateSource};

$this->app->singleton(TaxRateSource::class, fn ($app) => new ChainTaxRateSource([
    new CachingTaxRateSource(
        new TedbRateSource($app->make(\Illuminate\Http\Client\Factory::class), config('tax.tedb.url')),
        $app->make(\Illuminate\Contracts\Cache\Repository::class),
    ),
    new StaticTaxRateSource, // fallback
]));
```

> The adapters implement the documented feed shape; point them at a source you
> trust (the EU TEDB feed, the SST files transformed to JSON, a commercial adapter)
> and verify the data before relying on it in production.
