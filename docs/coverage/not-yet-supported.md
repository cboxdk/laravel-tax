---
title: Not yet supported
weight: 2
description: The boundary between published rate data and the calculation regimes implemented by this package.
---

# Not yet supported

The register covers more jurisdictions than the package's default regime registry.
The engine currently models the **52 countries** listed in
[Supported jurisdictions](supported.md). Other geo profiles have no modelled tax
module, so `DefaultTaxCalculator` raises `UnsupportedJurisdiction` before looking up
a rate.

For example, the register carries South Africa, Israel, Gabon and Argentina, but
the default engine does not assess supplies there. Syncing those regions alone
does not enable their place-of-supply, registration or customer-treatment rules.

## Adding a jurisdiction

Support requires a geo tax profile, a registered `TaxRegime` implementation and
verified rate data for the supplies it will assess. The regime must model the
relevant seller, customer, product and territorial rules. Countries with
sub-national or product-dependent taxes may need more than the generic national
regime.

Hosts can bind their own `RegimeRegistry` and jurisdiction repository; see
[Regimes](../core-concepts/regimes.md). A country with no modelled regime refuses
rather than returning zero. That refusal is not a statement that the country has
no tax.

## Product-category detail

The public API accepts the 56 `TaxClass` values. `CategoryMap` maps them into the
register's larger vocabulary, and some detailed register categories have no public
class. A commodity code refines the mapped category; `TaxQuery` and
`TaxRateSource` do not yet accept raw register category keys.

## Rule and history limits

- Mixed intrastate US sourcing needs decisions per authority layer. An identified
  mixed case raises `UnresolvedTaxRule` rather than selecting one place for all layers.
- US delivery requires an applicable transport/handling rule. A conditional
  exclusion also requires confirmed host facts. Independent taxation of delivery
  accompanying exempt goods and taxable allocation for partially exempt goods are not yet
  classified by the default regime; both refuse.
- Published rounding policies support half-up/up, line/invoice/seller-election
  scopes and combined local shares. Separate per-authority rounding and collection
  bracket alternatives are not implemented by this policy path.
- Local boundary artifacts are snapshots. Dated rate coverage does not establish
  historical boundary coverage.

See [Rounding and delivery](../core-concepts/rounding-and-delivery.md) for the
supported inputs and [cadastre feedback](../../conformance/cadastre-feedback.md)
for the remaining data and contract requests.

## E-invoicing and clearance

The package calculates tax and aggregates assessments. Mandatory e-invoicing,
clearance and submission to authorities belong to the invoicing or filing system.
They are outside this package's calculation scope.
