---
title: US SaaS taxability
weight: 4
description: The curated per-state SaaS taxability map the engine ships, with a citation per state and the states left undetermined for the operator to configure.
---

# US SaaS taxability

Whether **SaaS / cloud software** is subject to US sales tax varies state by state.
The engine ships a **curated `StaticProductTaxability` override map** for the
`digital_service` category, keyed `"US-XX:digital_service" => taxable`, bound by
default. It covers **only states with clear, citable guidance**; states where the
guidance conflicts, or is conditional/partial in a way a boolean cannot represent,
are **deliberately absent** so an operator configures them.

> **Not a substitute for tax advice.** The determinations below are drawn from
> **authoritative, dated practitioner compilations**, not from reading each
> statute. SaaS taxability is nuanced (bundling, delivery method, home-rule
> localities, B2B vs B2C) and changes. **Verify with your tax advisor before
> relying on this in production.**

## Sources

The map is curated from two recognised, dated SaaS-by-state compilations, retrieved
**2026-07-17**; only states where **both agree** on a clear taxable/exempt outcome
are shipped:

| Source | URL |
| --- | --- |
| TaxJar — "Software-as-a-service (SaaS) sales tax by state" | <https://www.taxjar.com/sales-tax/saas-sales-tax> |
| Anrok — "SaaS sales tax by state" | <https://www.anrok.com/saas-sales-tax-by-state> |

## Shipped — taxable (18 jurisdictions)

SaaS treated as **taxable** for the `digital_service` category. Citation: both
compilations above concur (retrieved 2026-07-17).

| State | Note |
| --- | --- |
| US-AZ Arizona | |
| US-CT Connecticut | Business use taxed at 1%, personal at the full rate; the map records "taxable" |
| US-DC District of Columbia | |
| US-HI Hawaii | General excise tax applies broadly |
| US-KY Kentucky | Taxable since 2023 |
| US-LA Louisiana | Taxable at state level; parish-level rules add complexity |
| US-MA Massachusetts | |
| US-NM New Mexico | Gross receipts tax applies broadly |
| US-NY New York | |
| US-PA Pennsylvania | |
| US-RI Rhode Island | |
| US-SC South Carolina | |
| US-SD South Dakota | |
| US-TN Tennessee | |
| US-UT Utah | |
| US-VT Vermont | |
| US-WA Washington | |
| US-WV West Virginia | |

## Shipped — exempt (22 states)

SaaS treated as **not taxable** at the state level for the `digital_service`
category. Citation: both compilations concur (retrieved 2026-07-17).

| State | Note |
| --- | --- |
| US-AR Arkansas | |
| US-CA California | No transfer of tangible personal property |
| US-CO Colorado | **State-level** exempt; home-rule cities (e.g. Denver) may tax |
| US-FL Florida | |
| US-GA Georgia | |
| US-ID Idaho | |
| US-IL Illinois | **State-level** exempt; Chicago's lease-transaction tax may apply |
| US-IN Indiana | |
| US-KS Kansas | |
| US-ME Maine | |
| US-MI Michigan | Downloadable components may still be taxable |
| US-MN Minnesota | |
| US-MO Missouri | |
| US-NE Nebraska | |
| US-NV Nevada | |
| US-NJ New Jersey | |
| US-NC North Carolina | |
| US-ND North Dakota | |
| US-OK Oklahoma | |
| US-VA Virginia | |
| US-WI Wisconsin | |
| US-WY Wyoming | |

## Undetermined — operator configures (absent from the map)

These are **not** in the shipped map. Because the taxability contract returns a
boolean, absent states fall through to the **safe over-collection default
(taxable)** — the engine cannot express "undetermined". **You must configure these
before invoicing SaaS in them**; do not rely on the default.

| State | Why undetermined |
| --- | --- |
| US-AL Alabama | Sources conflict (one taxable, one exempt) |
| US-MS Mississippi | Sources conflict (recent law change) |
| US-TX Texas | Partial: SaaS is a data-processing service, **80% taxable / 20% exempt** — not a boolean |
| US-IA Iowa | Conditional on B2B vs B2C (exempt for business use) |
| US-OH Ohio | Conditional on B2B vs B2C (taxable for business use) |
| US-MD Maryland | Conditional: business use taxed at a reduced rate, personal use fully taxable |
| US-AK Alaska | No statewide sales tax; home-rule localities set their own SaaS rules |

**No general sales tax** (SaaS not taxed at state level; omitted entirely):
Delaware, Montana, New Hampshire, Oregon.

## Scope of the map

- Covers the **`digital_service`** category only. Tangible goods (`standard`) remain
  **taxable-by-default**, which is generally correct.
- Determinations are **state-level**. Home-rule localities (Chicago; Colorado
  home-rule cities) may tax SaaS even where the state does not — resolving those
  needs a rooftop/local feed, which the package does not ship.
- Override any entry, or supply your own full map, by binding
  `Cbox\Tax\Contracts\ProductTaxability`.
