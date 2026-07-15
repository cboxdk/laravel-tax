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
**EU Commission TEDB** feed (all 27 + Northern Ireland). Confidence: **high**
(regime + threshold grounded from EU primary law; rates from the official EU feed).

| Countries | Regime | Rate source |
| --- | --- | --- |
| AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE | `eu-vat` | EU TEDB |

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
| **Singapore** | `sg-gst` | 9% | IRAS | high |

## India — dual GST (`in-gst`)

A dedicated regime. The customer-facing rate is uniform across the split, so the
amount is a single rate; the regime labels the components: **IGST** for
inter-state / imports / foreign (OIDAR) suppliers, **CGST+SGST** for intra-state.
Foreign B2C digital (OIDAR) is charged at destination (18% IGST); B2B to a
GST-registered recipient reverse-charges. Source: **CBIC** (OIDAR guidance,
IGST Act). Standard rate **18%** (post-22 Sep 2025 slab restructure). Confidence:
**high**.

## United States — sales tax (`us-sales-tax`)

Sub-federal. Three gates before a rate applies: the **state** must be resolved
(rooftop via an `AddressGeocoder`), the seller must have **nexus** in it, and the
product must be **taxable** there — else `NotRegistered` / `Exempt`. Rate data:
**SST Rate & Boundary files** (~24 member states), the **Colorado GIS API** and
**Alabama** downloadable rate file for those home-rule states, and a commercial
adapter for the rest (incl. Louisiana, which has no open feed). SaaS taxability is
curated per state (the SST taxability matrix has no SaaS definition). Confidence:
**high on logic; rate/taxability data is per-state and must be sourced**.

## Canada — GST/HST (`ca-gst`)

Province-level (Canada has no local sales tax), so a province fully determines the
combined rate. Cross-border non-resident B2B to a registered customer
self-assesses. Source: **CRA open dataset** + provincial ministries (QST via
Revenu Québec). Confidence: **high**.
