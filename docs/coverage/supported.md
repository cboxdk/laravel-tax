---
title: Supported jurisdictions
weight: 1
description: Regime, standard rate, authoritative source and confidence for each supported jurisdiction.
---

# Supported jurisdictions

Each row is modelled by a regime and resolves a rate. **Confidence** reflects how
well the *rate/rules* are grounded in primary sources; the shipped default rates
are illustrative starting points unless a live source is bound.

## EU — VAT (`eu-vat`)

All 27 member states. Destination VAT for B2C digital (Art. 58); intra-EU B2B to a
VIES-validated customer reverse-charges (Art. 44). Authoritative rate source: the
**EU Commission TEDB** feed (all 27 + Northern Ireland), via the
[`TedbRateSource`](../extension-points/rate-sources.md) adapter. Confidence:
**high** (regime + threshold grounded from EU primary law; rates from the official
EU feed once bound).

**€10,000 micro-business threshold (Art. 59c).** The regime is threshold-aware: a
seller established in a single member state, **below** the €10,000 combined
cross-border B2C threshold (current or preceding year) and **not** opted into OSS,
charges its **own (origin)** VAT on cross-border B2C supplies; once it opts in or
crosses the threshold, the general **destination** rule applies. The seller
supplies these signals on `SellerRegistrations::$oss` (`OssStatus`) — the engine
never guesses turnover, and absent an asserted status it applies the destination
rule. B2B reverse-charge is unaffected.

| Countries | Regime | Rate source |
| --- | --- | --- |
| AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE | `eu-vat` | EU TEDB (`TedbRateSource`, optional) → static fallback |

### Reduced / zero rates

The rate-source contract resolves rates by **taxability category**, so a supply
that legally carries a reduced or zero band (e-books, food, etc.) resolves one
**when the bound source supplies it**. The shipped static snapshot carries **no
reduced-rate table** — the package will not fabricate national reduced bands.
Supply bands to `StaticTaxRateSource`, or bind a TEDB export that carries a `bands`
map, to resolve reduced/zero rates (see
[rate sources](../extension-points/rate-sources.md)).

### Tax-ID validation — live-response verification still needed

The VAT-ID validators (VIES, HMRC, ABN Lookup) are **fail-safe by design**: an
unreachable service returns *inconclusive*, and the engine then charges tax rather
than granting reverse-charge relief it cannot prove — this design is correct and
unchanged. Note, however, that treating a validation as *conclusive* still depends
on the authority's **live response**; a stubbed or cached validation must not be
mistaken for a real-time VIES/HMRC confirmation before relying on reverse-charge.

## National VAT/GST regimes (`NationalTaxRegime`)

Destination tax at the national rate; cross-border B2B to a registered customer
reverse-charges.

| Country | Module | Std rate | Authoritative source | Confidence |
| --- | --- | --- | --- | --- |
| United Kingdom | `uk-vat` | 20% | HMRC | high |
| Switzerland | `ch-vat` | 8.1% | ESTV/FTA | high |
| Norway | `no-vat` | 25% | Skatteetaten (VOEC) | high |
| Australia | `au-gst` | 10% | ATO | high |
| New Zealand | `nz-gst` | 15% | IRD | high |
| Mexico | `mx-iva` | 16% | SAT | high |
| Singapore | `sg-gst` | 9% | IRAS | high |
| Taiwan | `tw-vat` | 5% | MOF (Business Tax Act) | high |
| United Arab Emirates | `ae-vat` | 5% | FTA (federal, all emirates) | high |
| Saudi Arabia | `sa-vat` | 15% | ZATCA | high |
| Bahrain | `bh-vat` | 10% | NBR | high |
| Oman | `om-vat` | 5% | OTA | high |
| Türkiye | `tr-vat` | 20% | Gazette (Decree 7346, 2023) | high |
| Chile | `cl-iva` | 19% | SII | high |
| Indonesia | `id-ppn` | 11% | DGT (effective via 11/12 base; **not** the 12% headline) | high |
| Vietnam | `vn-vat` | 10% | GDT (**temporary 8% cut through 2026-12-31** — bind a date-aware source) | high |
| Philippines | `ph-vat` | 12% | BIR (RA 12023) | high |
| Japan | `jp-ct` | 10% | NTA (consumption tax; ¥10M threshold) | high |
| South Korea | `kr-vat` | 10% | NTS | high |
| Thailand | `th-vat` | 7% | Revenue Department (VES regime) | high |
| Ukraine | `ua-vat` | 20% | STS | high |

> **Time-sensitive rate notes.** Indonesia's headline PPN is 12% but the *effective*
> rate on non-luxury supplies is **11%** (the 11/12 base mechanism) — the engine
> encodes 11%. Vietnam's statutory standard is **10%**, currently reduced to 8% for
> most supplies **through 31 Dec 2026**; the shipped default is the durable 10% —
> bind a date-aware rate source to apply the temporary cut. Türkiye rose to 20% on
> 10 Jul 2023; Saudi Arabia to 15% (Jul 2020); Bahrain to 10% (Jan 2022).

## India — dual GST (`in-gst`)

A dedicated regime. The customer-facing rate is uniform across the split, so the
amount is a single rate; the regime labels the components: **IGST** for
inter-state / imports / foreign (OIDAR) suppliers, **CGST+SGST** for intra-state.
Foreign B2C digital (OIDAR) is charged at destination (18% IGST); B2B to a
GST-registered recipient reverse-charges. Source: **CBIC** (OIDAR guidance,
IGST Act). Standard rate **18%** (post-22 Sep 2025 slab restructure). Confidence:
**high**.

## Malaysia — SST (`my-sst`)

A dedicated regime, **not** a destination VAT. A registered foreign digital-service
provider charges Malaysian **service tax on both B2C and B2B with no reverse
charge** — so this regime never reverse-charges, unlike the national VAT regimes.
Service tax **8%** (since 1 Mar 2024), RM 500,000 threshold. Source: **RMCD**.
Confidence: **high**.

## United States — sales tax (`us-sales-tax`) — LOGIC ONLY, not production-ready

> **The US regime is not production-ready for automatic tax.** The package ships
> the *logic* (the three gates below), but the *datasets* those gates need are
> **not shipped and must be bound before any US customer can be invoiced
> correctly.** Do not rely on the shipped defaults for US sales tax.

Sub-federal. Three gates before a rate applies: the **state** must be resolved
(via an `AddressGeocoder`), the seller must have **nexus** in it, and the product
must be **taxable** there — else `NotRegistered` / `Exempt`. What is modelled
versus what you must supply:

| Concern | Shipped | What is required for correctness |
| --- | --- | --- |
| Sourcing / nexus / taxability **logic** | ✅ the regime | — |
| Per-state SaaS **taxability** | ❌ `StaticProductTaxability` **defaults everything to taxable** (no SaaS map) | a per-state taxability dataset; SaaS taxability varies by state and the SST matrix has no SaaS definition |
| **Local (rooftop) rates** | ❌ only illustrative state base rates; the shipped `GeocodioGeocoder` resolves **state-level only** | a rooftop rate feed (e.g. SST Rate & Boundary files, home-rule feeds such as Colorado/Alabama, or a commercial adapter) — city/district rates stack on the state base and are not resolved without one |
| **Economic-nexus thresholds** | ❌ not evaluated — nexus is asserted only by an explicit seller `SellerRegistration` | a per-state economic-nexus threshold dataset if you want nexus determined from turnover/transaction counts rather than asserted |

Until a real taxability map, a rooftop rate feed, and (if needed) nexus-threshold
data are bound, the US regime will under- or over-charge. Confidence: **high on
logic; the required per-state datasets are NOT shipped.**

## Canada — GST/HST (`ca-gst`)

Province-level (Canada has no local sales tax), so a province fully determines the
combined rate — a cleaner structure than the US. Cross-border non-resident B2B to
a registered customer self-assesses. The shipped province rates are illustrative
defaults; an authoritative source (**CRA** open dataset + provincial ministries,
QST via Revenu Québec) should still be bound. Confidence: **high on logic; province
rates are DATA to source.**
