---
title: Overview
weight: 0
description: What the engine decides, what the register supplies, and where to start reading.
---

# Cbox Tax

`cboxdk/laravel-tax` assesses consumption tax — VAT, GST, US sales tax — on a supply
or on a whole document. You send the facts of the sale; it answers with a treatment, a
rate, the authorities the tax splits across, and the published source it came from.

## The mental model

```
your app ──► TaxQuery ──► regime  (this package: place of supply, reverse charge,
                          │        registration, exemptions, rounding)
                          ▼
                      the register  (published data: rates, rules,
                          │          boundaries, provenance)
                          ▼
                    TaxAssessment
```

**The engine owns the logic. The register owns the data.** Neither crosses into the
other: the engine never invents a rate, and no rate table decides whether a supply is
a reverse charge. Both halves sit behind contracts, so a host can replace either.

The register is compiled to local disk by `php artisan tax:data:sync` and read without
a network call — nothing is fetched while pricing. Jurisdictions come from
[`cboxdk/laravel-geo`](https://github.com/cboxdk/laravel-geo), so a supply is placed
against canonical ISO data rather than a country-name match.

## Three answers, kept apart

The engine is built around a distinction most calculators collapse:

- **A resolved zero** — the place levies nothing, or the supply is zero-rated or
  exempt under a published band.
- **A zero that is not yours to charge** — a reverse charge, a marketplace's
  liability, or a country the seller is not registered in.
- **Not determinable** — an exception. No regime, no published rate, a rule the
  register says it has not modelled. Never a plausible number.

And between them, the honest middle: an answer that is probably right, flagged with
what was missing and the one step that would close it.

## Where to start

| | |
| --- | --- |
| [Quickstart](quickstart.md) | Install the data, price a supply, read the result |
| [Getting started](getting-started/_index.md) | Installation, the register, testing, upgrading from 0.9 |
| [Cookbook](cookbook/_index.md) | A checkout, a marketplace sale, a backfill |
| [Core concepts](core-concepts/_index.md) | Regimes, seller registrations, dates, exemptions, rounding, breakdowns |
| [Coverage](coverage/_index.md) | What is modelled, what the register publishes, what is neither |
| [Extension points](extension-points/_index.md) | Bind your own sources, geocoder, catalogue, resolvers |
| [Decisions](decisions/_index.md) | Why particular places are modelled the way they are |
