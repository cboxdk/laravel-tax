---
title: EU VAT rate feed
weight: 3
description: The real, public, MIT-licensed EU VAT-rate dataset the engine binds — source, license, shape and how it composes with the static fallback.
---

# EU VAT rate feed

The engine can source live EU member-state VAT rates from a **real, public,
permissively-licensed dataset** instead of the shipped static snapshot. This page
documents exactly which dataset, under what license, and how it is wired.

## The dataset

| | |
| --- | --- |
| **Name** | `ibericode/vat-rates` — "Community maintained resource for VAT rates of EU member states" |
| **URL (canonical)** | `https://raw.githubusercontent.com/ibericode/vat-rates/master/vat-rates.json` |
| **Repository** | <https://github.com/ibericode/vat-rates> |
| **License** | **MIT** (permissive) — see the repository's `LICENSE` |
| **Provenance** | A community-maintained fork of `adamcooke/vat-rates`; a compilation of EU VAT rates, **not** the EU Commission TEDB itself |
| **Coverage** | The 27 EU member states plus the UK (28 country entries) |
| **Adapter** | [`IbericodeVatRateSource`](../extension-points/rate-sources.md) |

> **Honest provenance.** This is a *community compilation*, not a primary tax
> authority. The maintainers state plainly that they "cannot be held responsible
> for its accuracy or completeness." Treat it as a good, refreshable default —
> pin/mirror a copy and re-verify against each member state's own guidance before
> relying on it for filing. For a primary-source feed, point the config URL at an
> EU Commission TEDB export instead (see [rate sources](../extension-points/rate-sources.md)).

## Dataset shape

Schema `version: 4`. Each country maps to a list of rate **periods** (newest last),
keyed by `effective_from` (`YYYY-MM-DD`, with `0000-01-01` as the open-ended base):

```json
{
  "details": "https://github.com/ibericode/vat-rates",
  "version": 4,
  "items": {
    "DK": [ { "effective_from": "0000-01-01", "rates": { "standard": 25 } } ],
    "FR": [
      { "effective_from": "2014-01-01",
        "rates": { "super_reduced": 2.1, "reduced1": 5.5, "reduced2": 10, "standard": 20 },
        "exceptions": [ { "name": "Guadeloupe", "postcode": "971\\d{2,}", "standard": 8.5 } ] }
    ]
  }
}
```

- The adapter selects the period **in force** at the assessment date (`$at`, or
  now) and resolves the **standard** rate authoritatively.
- The dataset carries reduced tiers (`reduced`, `reduced1`, `reduced2`,
  `super_reduced`, `parking`, `press_publications`) but **does not map them to
  product categories**. The adapter therefore resolves the standard rate by default
  and only returns a reduced tier when an operator supplies an authoritative
  category → tier map — the package never guesses which tier a category belongs to.
  (EU e-services are generally standard-rated; e-books and similar reduced cases
  need an explicit mapping.)
- Territorial `exceptions` (Canary Islands, French overseas départements, …) are
  postcode-scoped and **not** applied at country granularity.

## How it is wired

Disabled by default — the static snapshot stays the zero-config default. Enable the
feed and the provider composes `ChainTaxRateSource(EU feed → static snapshot)`: the
feed is authoritative, the shipped static rates are the fallback (deny-by-default is
preserved — if neither has a rate, the engine denies).

```dotenv
TAX_EU_VAT_FEED=true
# Optional: override the URL to a pinned mirror or a TEDB export.
# TAX_EU_VAT_URL=https://your-mirror.example/vat-rates.json
```

A URL source is wrapped in `CachingTaxRateSource` automatically (one request per
lookup otherwise); a local file path is read directly. The config URL is
config-driven end-to-end — see [`config/tax.php`](../../config/tax.php) and
[rate sources](../extension-points/rate-sources.md).
