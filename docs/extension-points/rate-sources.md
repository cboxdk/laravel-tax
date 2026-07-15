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

## Composing sources

The package ships composable sources so you can assemble a live feed with a safe
fallback:

- **`StaticTaxRateSource`** — the built-in map (default binding).
- **`RemoteRateSource`** — fetches a JSON country→rate feed (e.g. an EU TEDB-derived
  dataset); one request per lookup, so wrap it in caching.
- **`CachingTaxRateSource`** — caches the current rate from an inner source; a
  date-specific lookup bypasses the cache.
- **`ChainTaxRateSource`** — tries sources in order, first hit wins.

```php
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\RateSource\{ChainTaxRateSource, CachingTaxRateSource, RemoteRateSource, StaticTaxRateSource};

$this->app->singleton(TaxRateSource::class, fn ($app) => new ChainTaxRateSource([
    new CachingTaxRateSource(
        new RemoteRateSource($app->make(\Illuminate\Http\Client\Factory::class), 'https://your-feed/eu-vat.json', 'tedb'),
        $app->make(\Illuminate\Contracts\Cache\Repository::class),
    ),
    new StaticTaxRateSource, // fallback
]));
```

> The `RemoteRateSource` implements the documented feed shape; point it at a source
> you trust (the EU TEDB feed, the SST files transformed to JSON, a commercial
> adapter) and verify the data before relying on it in production.
