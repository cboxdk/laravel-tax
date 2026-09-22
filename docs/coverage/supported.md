---
title: Supported jurisdictions
weight: 1
description: The engine's modelled regimes and how their coverage differs from the register's data coverage.
---

# Supported jurisdictions

The default geo profiles and regime registry model **52 countries**. A usable
assessment also requires the relevant register data and enough information about
the seller, customer and supply. A rate existing in the register does not by itself
add a country to the engine's regime registry.

## Modelled regimes

| Countries | Engine module | Calculation path |
| --- | --- | --- |
| AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE | `eu-vat` | EU place of supply, B2B reverse charge and the scoped micro-business threshold |
| GB CH NO AU NZ MX SG TW AE SA BH OM TR CL ID VN PH JP KR TH UA | National VAT/GST modules | `NationalTaxRegime`, including its cross-border B2B treatment |
| IN | `in-gst` | India GST with IGST or CGST/SGST component labels |
| MY | `my-sst` | Malaysia service tax without the generic VAT reverse-charge treatment |
| US | `us-sales-tax` | Registration, marketplace, taxability, sourcing and local-authority gates |
| CA | `ca-gst` | Province-level combined rate and cross-border B2B self-assessment |

See [Regimes](../core-concepts/regimes.md) for the rules each implementation models
and [Unsupported jurisdictions](not-yet-supported.md) for the remaining boundary.

## Rates and confidence

Every default rate comes from `RegisterRateSource`. Run `tax:data:sync` before
pricing. The register supplies dated standard, reduced and zero rates; there is no
bundled national snapshot or illustrative province-rate fallback.

A commodity code can refine a category lookup. If several applicable rates remain
ambiguous, the source uses the standard rate with `RateLimit::HeadingAmbiguous`.
Conditions that narrow an inherited category answer are reported with
`RateLimit::ConditionsUnevaluated`. Read `confidence`, `limitedBy` and `provenance`
on the returned rate rather than treating an entire country's coverage as one
confidence grade. See [Rate sources](../extension-points/rate-sources.md).

Rates are resolved against the supply date where the register carries that window.
A missing historical rate does not become today's rate. Source monitoring and data
publication belong to the register; the package updates its local copy through sync.

## US address precision

The available paths are described in [register coverage](the-register.md): ZIP+4,
optional street indexes, polygons and county names. Postal and polygon artifacts
are included in sync by default; street indexes are selected with `--streets=KS,WA`.
Enable `tax.geocodio.rooftop` to have the shipped geocoder attach ZIP+4 or point
localities. County resolution works without that option.

Where the applicable local authorities cannot be resolved, a state share can be
returned as `Derived` with `NoLocalResolution`. It must not be treated as a complete
address-level rate. [SaaS taxability](us-saas-taxability.md) and
[nexus thresholds](us-nexus-thresholds.md) describe two separate inputs to the US
regime.

## Tax-ID validation

VIES, HMRC and optional ABN Lookup validators are separate from the register. An
unavailable validation service returns an inconclusive result. Store the actual
validation result when relying on it for the customer treatment.
