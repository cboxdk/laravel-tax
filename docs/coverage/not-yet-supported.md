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
  scopes and combined local shares. Separate per-authority rounding is not
  implemented by this policy path. A bracket table is applied exactly to a
  tax-exclusive sale; a tax-inclusive price, or a local share stacked on a state's
  table, falls back to the table's per-dollar rate, flagged.
- The EU margin scheme for second-hand goods, art and antiques is not modelled: it
  needs the purchase price and the seller's election. A host that uses it computes it
  before the engine.
- The domestic reverse charge of Art. 194 — a supplier not established in a member
  state selling to a taxable person there — is not read from the register's rules
  until they say which supplies they cover. Until then a non-established supplier's
  domestic B2B supply is reverse-charged as before, citing Art. 196, which is right
  for services and not for every member state's goods: Germany reverses work
  deliveries and services but not plain deliveries of goods.
- Local boundary artifacts are snapshots. Dated rate coverage does not establish
  historical boundary coverage.

See [Rounding and delivery](../core-concepts/rounding-and-delivery.md) for the
supported inputs and [cadastre feedback](../../conformance/cadastre-feedback.md)
for the remaining data and contract requests.

## E-invoicing and clearance

The package calculates tax and aggregates assessments. Mandatory e-invoicing,
clearance and submission to authorities belong to the invoicing or filing system.
They are outside this package's calculation scope.
