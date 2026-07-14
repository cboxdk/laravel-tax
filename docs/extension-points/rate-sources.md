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
