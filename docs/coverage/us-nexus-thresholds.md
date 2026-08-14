---
title: US economic-nexus thresholds
weight: 5
description: The cited per-state economic-nexus (Wayfair) threshold table the engine ships, its source, and how it flags a likely registration obligation.
---

# US economic-nexus thresholds

> **Now dataset-backed.** The default `NexusThresholds` source is the
> [us-tax-data dataset](us-tax-dataset.md); the cited static table below is the
> fallback used when the dataset is disabled. The *Wayfair* model and the engine's
> use of the thresholds are unchanged.

After *South Dakota v. Wayfair* (2018), a remote seller with no physical presence
can still owe sales tax once its sales into a state cross that state's **economic
nexus** threshold. These thresholds are **published and largely stable**, so the
engine ships them as a cited data table feeding a `NexusThresholds` source.

## What the engine does with them

The `us-sales-tax` regime still asserts nexus from an **explicit seller
registration** — it never infers nexus from a single invoice, because economic
nexus turns on the seller's **cumulative** sales/transactions in the state over a
measuring period, which one supply does not carry. What the threshold table adds:

- When a supply resolves to a state where the seller is **not** registered, the
  `NotRegistered` assessment reason is **annotated with that state's threshold** —
  flagging the operator to check whether the *Wayfair* trigger has been crossed and
  a registration is now required.
- `NexusThreshold::describe()` renders the figures for that annotation.

This package deliberately stops there. It does **not** decide whether a seller has
crossed a threshold, because the figures alone cannot answer it: the verdict turns
on the state's measuring **period** (rolling twelve months, previous calendar year,
the four preceding VAT quarters) and on which sales **basis** it measures (gross,
retail, taxable). A comparison made without those is a guess wearing the clothes of
a determination. `cboxdk/laravel-nexus` models both, and reports `Unknown` when the
seller's totals are measured on a different footing than the state uses.

## Source

| | |
| --- | --- |
| **Source** | Sales Tax Institute — *Economic Nexus State Guide* |
| **URL** | <https://www.salestaxinstitute.com/resources/economic-nexus-state-guide> |
| **Retrieved** | 2026-07-17 |
| **Nature** | An authoritative, dated practitioner compilation of each state's post-*Wayfair* threshold |
| **Source class** | `Cbox\Tax\Nexus\StaticNexusThresholds` |

> Transaction-count thresholds are being **widely repealed** — the dollar figure is
> the durable trigger. Re-verify against the state's own guidance before relying on
> a transaction count.

## Thresholds

Dollar figures are the annual gross-sales trigger. "Combinator" is how the sales and
transaction thresholds combine.

| Threshold | States | Combinator |
| --- | --- | --- |
| **$500,000** | California, Texas | sales only |
| **$500,000 and 100 transactions** | New York | both required |
| **$250,000** | Alabama, Mississippi | sales only |
| **$100,000 and 200 transactions** | Connecticut | both required |
| **$100,000 or 200 transactions** | Maryland, Michigan, Minnesota, Nebraska, Nevada, New Jersey, Rhode Island, Vermont | either trigger |
| **$100,000** | Alaska†, Arizona, Arkansas, Colorado, DC, Florida, Georgia, Hawaii, Idaho, Illinois, Indiana, Iowa, Kansas, Louisiana, Maine, Massachusetts, Missouri, New Mexico, North Carolina, North Dakota, Ohio, Oklahoma, Pennsylvania, South Carolina, South Dakota, Tennessee, Utah, Virginia, Washington, West Virginia, Wisconsin, Wyoming, Kentucky* | sales only |

\* Kentucky **repealed** its 200-transaction prong effective **2026-08-01** — HB 757
(2026 RS) amended KRS 139.340 and 139.450; the $100,000 gross-receipts threshold is
unchanged. Verified against the Department of Revenue's own *Sales Tax Facts*,
Summer 2026. **The published dataset still carries the transaction prong on an
open-ended window**, so a deployment on the default source is applying a repealed
test until `us-tax-data` is corrected and re-published; the static fallback here is
right.
† Alaska has no statewide sales tax; the $100,000 figure is the statewide threshold
set by the **Alaska Remote Seller Sales Tax Commission** for local sales taxes.

**No general sales tax — absent from the table (returns `null`):** Delaware,
Montana, New Hampshire, Oregon.

## Honest scope

This is DATA that states amend. The table is a **decision aid**, not an automatic
nexus determination — the engine will not register you or start collecting on its
own. **Verify your obligations with your tax advisor.** Override any figure, or bind
your own source, via `Cbox\Tax\Contracts\NexusThresholds`.
