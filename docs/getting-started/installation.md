---
title: Installation
weight: 1
description: Install via Composer, then sync the register — a deploy step, not a one-off.
---

# Installation

```bash
composer require cboxdk/laravel-tax
```

Then sync the register, before anything is priced:

```bash
php artisan tax:data:sync
```

**This is a deploy step, not a one-off.** Until it has run the engine refuses rather
than guessing, and the refusal names the command. Put it alongside
`php artisan migrate`. See [The register](the-register.md) for what it pulls, how to
take less than all of it, and what the licence permits.

`TaxServiceProvider` is auto-discovered and binds:

- `Contracts\TaxCalculator` → `DefaultTaxCalculator`
- `Contracts\RegimeRegistry` → the shipped regimes (`DefaultRegimeRegistry::withDefaults()`)
- `Contracts\TaxRateSource` → `RegisterRateSource` (the compiled register — run `php artisan tax:data:sync` first)

To put your own source in front of the register, bind `TaxRateSource` in a service
provider — see [Rate sources](../extension-points/rate-sources.md). Publish the config
with:

```bash
php artisan vendor:publish --tag=tax-config
```
