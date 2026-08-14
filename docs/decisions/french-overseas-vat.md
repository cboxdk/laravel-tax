---
title: French overseas territories
weight: 53
description: Measured 2026-08-14 — Martinique, Guadeloupe and Réunion charge 8.5% French VAT and this engine charges nothing. Guyane and Mayotte are correct.
---

# French overseas territories

**Date:** 2026-08-14 · **Status:** verified, not yet implemented

## What the engine does today

All five resolve `isEuMember = false`, so an EU VAT assessment returns nothing and
the supply is treated as an export:

```
MQ Martinique   GP Guadeloupe   RE Réunion   GF Guyane   YT Mayotte   → 0%
```

## What the law says

**Guadeloupe, Martinique, La Réunion — VAT applies.** CGI art. 296 sets a normal
rate of **8.5%** and a reduced rate of **2.1%**. CGI art. 296 bis adds 1.75% on
sales of live meat animals to non-taxable persons and 1.05% on the first 140
theatrical performances; CGI art. 298 septies sets 1.05% on qualifying press.
Confirmed by the French tax administration's own doctrine, BOI-TVA-GEO-20-10.

**Guyane and Mayotte — VAT does not apply.** CGI art. 294 provides that VAT is
provisionally not applicable there, neither internally nor on imports.

TEDB says the same thing in its own words, under the `REGION` heading: *"The
standard VAT rate in Martinique, Guadeloupe and Réunion is 8.5%. The reduced rate is
2.1%."*

## So two of the five are right and three are wrong

`isEuMember = false` is **legally correct for all five**: the French overseas
departments are excluded from the EU VAT territory by Directive 2006/112/EC art. 6.
What follows from it is not. France levies its own VAT in three of them under
national law, outside the harmonised system — no intra-EU rules, no OSS, but a
seller shipping there charges 8.5%.

**The direction matters.** Ceuta and Melilla over-charged, and a customer can be
refunded. This under-charges: the seller owes tax it never collected, and finds out
at audit.

## Why it is not implemented here yet

The shape does not fit the existing seam. `StaticEuTerritories` is keyed on a member
state plus a postal code, and these arrive as their own ISO country codes — so the
fix is not a territory entry but a decision about where a non-EU jurisdiction with a
modellable national VAT belongs. That is the same question Corsica raises from the
other side: it IS in France's VAT territory, at special rates (CGI art. 297 — 0.9%,
2.1%, 10%, 13%), and has no entry at all.

Both need a home before either is written. Guessing at it produced the measurement
error that found this in the first place.

## Sources

- CGI art. 294, 296, 296 bis, 298 septies
- BOI-TVA-GEO-20-10, *Taux applicables en Guadeloupe, en Martinique et à La Réunion*
- Directive 2006/112/EC art. 6
- TEDB `REGION` heading, captured 2026-08-14
