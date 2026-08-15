---
title: Requirements
weight: 2
description: PHP and Laravel versions and the direct dependencies the engine enforces.
---

# Requirements

From `composer.json`:

- **PHP** `^8.4`
- **`ext-dom`** — the live [EU TEDB source](extension-points/rate-sources.md#the-eu-tedb-service-tedbsoapratesource)
  parses the Commission's SOAP responses with `DOMDocument`/`DOMXPath`.
- **`ext-zlib`** — the US boundary indexes are published gzipped (5.4 MB across the
  24 SST states instead of 20 MB) and inflated on read.
- **Laravel** `^13` (`illuminate/contracts`, `illuminate/support`, `illuminate/http`)
- **[`cboxdk/laravel-geo`](https://github.com/cboxdk/laravel-geo)** `^0.6` — the
  canonical jurisdiction reference every assessment binds to.
- **[`brick/money`](https://github.com/brick/money)** `^0.14` — exact integer-minor-unit
  money for amounts and rate maths.

No migration is required. Rate data is supplied by a `TaxRateSource`; the default
binding ships representative national rates for out-of-the-box use.

## Why Laravel 13 only

A library should install on the current **and previous** Laravel major, and this one
does not. That is a deliberate decision with a specific external cause, not an
oversight:

- `brick/money ^0.14` — the exact-money library every amount and rate calculation
  runs through — requires `brick/math ~0.15` or newer.
- `laravel/framework` **12.64** caps `brick/math` at `^0.11|^0.12|^0.13|^0.14`.

The ranges are disjoint, so the two cannot be installed together. Supporting
Laravel 12 would mean pinning `brick/money` back to `^0.11` — three minors and a
different rounding surface — for a **tax** engine, where the money library's
behaviour is the calculation. Reach is not worth that trade.

Note that `illuminate/support`, `illuminate/contracts` and `illuminate/http` never
require `brick/math` themselves; the cap lives in the full framework package, and
only from 12.64 onward. The constraint will be widened to `^12.0 || ^13.0` if a
Laravel 12 patch relaxes it — nothing in this package's own graph prevents it.
