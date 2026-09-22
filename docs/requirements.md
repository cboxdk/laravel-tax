---
title: Requirements
weight: 2
description: PHP and Laravel versions and the direct dependencies the engine enforces.
---

# Requirements

From `composer.json`:

- **PHP** `^8.4`
- **`ext-dom`** — XML parsing for tax-ID validation.
- **`ext-zlib`** — the register is served gzipped (1.8 MB against 54 MB of JSON) and
  inflated on read.
- **[`cboxdk/tax-resolver`](https://github.com/cboxdk/tax-resolver)** `^1.0` — the
  shared address-to-jurisdiction resolver. The register runs the SAME code over its
  own artifacts at build time, which is the only thing that keeps two readers of one
  format from drifting apart.
- **Laravel** `^13` (`illuminate/contracts`, `illuminate/support`, `illuminate/http`)
- **[`cboxdk/laravel-geo`](https://github.com/cboxdk/laravel-geo)** `^0.6` — the
  canonical jurisdiction reference every assessment binds to.
- **[`brick/money`](https://github.com/brick/money)** `^0.14` — exact integer-minor-unit
  money for amounts and rate maths.

No database migration is required. The default `TaxRateSource` reads a local
register compiled by `php artisan tax:data:sync`; a fresh install refuses until
that data is available. See [The register](getting-started/the-register.md).

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
