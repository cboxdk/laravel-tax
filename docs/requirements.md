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
- **[`brick/money`](https://github.com/brick/money)** `^0.14 || ^0.15` — exact
  integer-minor-unit money for amounts and rate maths.

No database migration is required. The default `TaxRateSource` reads a local
register compiled by `php artisan tax:data:sync`; a fresh install refuses until
that data is available. See [The register](getting-started/the-register.md).

## Why Laravel 13 only

A library should install on the current **and previous** Laravel major, and this one
does not. The cause is external and it is not a constraint this package can widen its
way out of:

- `brick/money` — the exact-money library every amount and rate calculation runs
  through — has required `brick/math ~0.15` or newer since its 0.12 release.
- **Every** `laravel/framework` 12 release requires `brick/math` and caps it below
  that: `^0.11|^0.12` at v12.0.0, `^0.11|^0.12|^0.13|^0.14` in the current patches.

The ranges are disjoint, so an application on Laravel 12 cannot install a current
`brick/money` at all — with or without this package. Widening
`illuminate/*` to `^12.0 || ^13.0` here would not change that: the subsplits
(`illuminate/support`, `illuminate/contracts`, `illuminate/http`) do not require
`brick/math` themselves, so this package would merely *resolve* against Laravel 12 in
isolation and then fail in any real application, which is a worse failure than an
honest constraint.

The alternative is pinning `brick/money` back to `^0.11`, the newest release that
accepts `brick/math ~0.14`. For a tax engine the money library's behaviour **is** the
calculation — rounding, allocation, minor units — so trading three minors of it for
reach on a superseded framework major is the wrong way round.

This widens the day Laravel 12 accepts `brick/math ~0.15`, which a stable major is
unlikely to do, or the day Laravel 12 stops being the previous major.
