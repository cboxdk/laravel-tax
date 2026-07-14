---
title: Requirements
weight: 2
description: PHP and Laravel versions and the direct dependencies the engine enforces.
---

# Requirements

From `composer.json`:

- **PHP** `^8.4`
- **Laravel** `^12 || ^13` (`illuminate/contracts`, `illuminate/support`)
- **[`cboxdk/laravel-geo`](https://github.com/cboxdk/laravel-geo)** `^0.1` — the
  canonical jurisdiction reference every assessment binds to.
- **[`brick/money`](https://github.com/brick/money)** `^0.14` — exact integer-minor-unit
  money for amounts and rate maths.

No migration is required. Rate data is supplied by a `TaxRateSource`; the default
binding ships representative national rates for out-of-the-box use.
