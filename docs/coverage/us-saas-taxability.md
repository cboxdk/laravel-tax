---
title: US SaaS taxability
weight: 4
description: How the register answers US SaaS taxability, including the changed fallback for undetermined categories.
---

# US SaaS taxability

The default `ProductTaxability` binding is `RegisterTaxability`. It reads the
installed register and maps `TaxClass::DigitalService` into the register's category
vocabulary. SaaS, prewritten software, custom software, hosting and data processing
have separate classes; choose the one that describes the supply.

## How a determination is made

For the supply's jurisdiction, amount and date, the source checks:

1. A published price exemption, including what happens above its threshold.
2. The nearest category with a published rate or exemption. A category is exempt
   only when every applicable record at that level is zero-rated or exempt.
3. Taxable when the jurisdiction has rate records but no category determination.

An incomplete price exemption refuses with `UnresolvedProductTaxability`. A
jurisdiction with no rate records also refuses. The US regime applies the result
after its registration and marketplace checks.

## Behaviour changed with the register migration

The retired dataset carried an explicit `undetermined` verdict for some
state/category pairs, which made the engine refuse. The register has no equivalent
per-category marker. Those pairs now follow the taxable fallback above.

This can change the tax charged. Review the states and product classes you sell
into when upgrading; the old static SaaS table is no longer a fallback. Bind your
own `Contracts\ProductTaxability` when you need a more specific determination.

## Taxability and rate precision

A taxable determination does not establish an address-level rate. The register
source also needs the applicable local authorities. Where resolution stops at the
state share, the rate carries `Confidence::Derived` and
`RateLimit::NoLocalResolution` if local tax may be missing.

See [register coverage](the-register.md) and [geocoding](../extension-points/geocoding.md)
for the available resolution paths. Keep the assessment's rate provenance and
limitations with the invoice so the data release and unresolved questions remain
visible.
