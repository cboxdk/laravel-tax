---
title: Overview
weight: 0
description: A self-hostable consumption-tax engine that owns the calculation logic and sources only rate data.
---

# Cbox Tax

`cboxdk/laravel-tax` assesses consumption tax (VAT / GST / sales tax) on a supply.
It **owns the calculation logic** — place-of-supply, reverse-charge, rate
application, inclusive/exclusive — and **sources only the rate data** behind a
pluggable contract, so no third-party calculation SaaS is required.

Every supply is assessed against a jurisdiction resolved from
[`cboxdk/laravel-geo`](https://github.com/cboxdk/laravel-geo), so
`tax = f(seller registrations, buyer jurisdiction, product type)`.

## Sections

- [Getting started](getting-started/_index.md)
- [Core concepts](core-concepts/_index.md) — architecture, regimes, and exemptions.
- [Extension points](extension-points/_index.md) — rate sources and custom regimes.
