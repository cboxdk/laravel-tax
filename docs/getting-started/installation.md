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

## Upgrading from the retired datasets

Merge the new published config into your application's existing `config/tax.php`.
Replace `us_tax_data`, `eu_tax_data` and `tedb` settings with `register` settings,
and add `tax:data:sync` to deployment. The unused `register.enabled` switch has
been removed; bind your own source when replacing the register.

Move `us_tax_data.rooftop` to `geocodio.rooftop` (`GEOCODIO_ROOFTOP`). The old
`TAX_US_DATASET_ROOFTOP` environment variable remains a fallback when the new
variable is unset. Other retired dataset options have no effect.

Check [SaaS taxability](../coverage/us-saas-taxability.md) for the changed handling
of undetermined US categories. Use [FakeRegister](testing.md) to provide explicit
test data in applications that previously depended on bundled rates.
