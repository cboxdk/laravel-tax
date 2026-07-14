---
title: Architecture
weight: 1
description: Query → regime selection → assessment, and the logic-vs-data boundary.
---

# Architecture

A `TaxQuery` carries the amount, whether it is net or gross, the buyer
jurisdiction (place of supply, resolved from `laravel-geo`), the customer type,
the product category, and the selling entity's registrations.

`DefaultTaxCalculator` reads the place of supply's tax profile, selects the
`TaxRegime` keyed by its `regimeModule`, and delegates. The regime returns a
`TaxAssessment`: treatment, the net/tax/gross split, the place of supply, the rate
applied, and a human-readable reason.

## Own the logic, source the data

- **Logic (owned):** place-of-supply, B2B/B2C reverse-charge determination,
  inclusive/exclusive handling, rate application and rounding, and the assessment
  itself all live in the engine.
- **Data (sourced):** the rate number comes from a `TaxRateSource` — an EU TEDB
  feed, the SST files, or a commercial adapter. The engine decides *whether and
  how* to apply it.

## Deny-by-default

- No regime modelled for a jurisdiction → `UnsupportedJurisdiction`.
- No rate available from the source → `UnresolvedTaxRate`.

Neither ever degrades to a silent 0% — a wrong tax outcome is a real liability.
