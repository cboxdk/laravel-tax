---
title: Installation
weight: 1
description: Install via Composer; the provider and config auto-register.
---

# Installation

```bash
composer require cboxdk/laravel-tax
```

`TaxServiceProvider` is auto-discovered and binds:

- `Contracts\TaxCalculator` → `DefaultTaxCalculator`
- `Contracts\RegimeRegistry` → the shipped regimes (`DefaultRegimeRegistry::withDefaults()`)
- `Contracts\TaxRateSource` → `StaticTaxRateSource` (representative national rates)

To use live rate data, bind your own `TaxRateSource` in a service provider — see
[Rate sources](../extension-points/rate-sources.md). Publish the config with:

```bash
php artisan vendor:publish --tag=tax-config
```
