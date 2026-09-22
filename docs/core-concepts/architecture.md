---
title: Architecture
weight: 1
description: Query → regime selection → assessment, and the logic-vs-data boundary.
---

# Architecture

A `TaxQuery` carries the amount, whether it is net or gross, the buyer
jurisdiction (place of supply, resolved from `laravel-geo`), the customer type,
the product category, the selling entity's registrations, and an optional buyer
[exemption](exemptions.md).

`DefaultTaxCalculator` reads the place of supply's tax profile, selects the
`TaxRegime` keyed by its `regimeModule`, and delegates. The regime returns a
`TaxAssessment`: treatment, the net/tax/gross split, the place of supply, the rate
applied, a human-readable reason, and (only when a buyer certificate drove the
outcome) the applied exemption.

## Exemption override

After the regime returns its verdict, the calculator applies any
`TaxQuery::$exemption` deny-by-default: a valid exemption that covers the taxed
jurisdiction rewrites a would-be `Standard` line to `Exempt` (net kept, tax 0),
and leaves every other treatment — reverse-charge, not-registered, zero-rated,
already-exempt — untouched. See [exemptions](exemptions.md).

## Own the logic, source the data

- **Logic (owned):** place-of-supply, B2B/B2C reverse-charge determination,
  inclusive/exclusive handling, rate application and rounding, and the assessment
  itself all live in the engine.
- **Data (sourced):** the register supplies percentages, effective windows,
  category scopes, rule parameters and boundary artifacts. Contracts allow a host
  to replace these sources. The engine decides *whether and how* to apply them.
- **Adapter logic:** compiling artifacts, translating classifications and choosing
  applicable records are executable code, even under the `Register` namespace.
- **Host inputs:** amounts, seller registrations, product mappings, addresses,
  validated tax IDs, exemptions and supply dates describe the transaction.

Some reference facts still ship in PHP: `StaticEuTerritories` carries postal ranges
and regional rate substitutions; `UsLocalStructure` carries state lists;
`CategoryMap` maps public classes to register categories. They remain data or
maintained mappings by meaning, irrespective of their storage format. Moving the
main rate source to the register has not externalized every tax fact.

The [data/engine validation matrix](../../conformance/validation-matrix.md) records
the current boundary and evidence. Fixture tests check logic against controlled
data; independent reference cases check the assembled system. Passing the latter
does not isolate data correctness from correct interpretation and calculation.

## Deny-by-default

- No regime modelled for a jurisdiction → `UnsupportedJurisdiction`.
- No rate available from the source → `UnresolvedTaxRate`.
- Conflicting/unsupported published rules or missing delivery facts → `UnresolvedTaxRule`.

The [rounding and delivery](rounding-and-delivery.md) contracts keep these rules
sourced while the engine applies them and reconciles the invoice. Remaining
publication requests are recorded in [cadastre feedback](../../conformance/cadastre-feedback.md).

Neither ever degrades to a silent 0% — a wrong tax outcome is a real liability.
