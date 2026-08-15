---
title: French overseas territories
weight: 53
description: Measured 2026-08-14 — the engine's 0% is correct. An earlier version of this page said it was a P0 under-collection; that was wrong, and this records why.
---

# French overseas territories

**Date:** 2026-08-14 · **Status:** verified — no change needed, and a correction

## The conclusion

Charging **0%** on a supply from an EU member state to Martinique, Guadeloupe,
Réunion, French Guiana or Mayotte is **correct**. The engine already does this, and
nothing needs to change.

## An earlier version of this page said the opposite

It claimed three of the five were under-charged at 8.5% and called it a P0. That
was wrong. The reasoning that produced it is worth keeping, because the mistake is
an easy one to make twice.

TEDB states, under its `REGION` heading: *"The standard VAT rate in Martinique,
Guadeloupe and Réunion is 8.5%."* That is true. CGI art. 296 sets exactly that rate,
and the French tax administration's own doctrine (BOI-TVA-GEO-20-10) confirms it.

The error was inferring from "a VAT of 8.5% exists there" that **an EU seller
shipping there charges it**. It does not follow.

## What the Directive actually says

**Article 6(1) of Directive 2006/112/EC excludes the French overseas departments
from the Directive entirely** — alongside Mount Athos, the Canary Islands, the
Åland Islands and the Channel Islands. They are outside the EU VAT territory.

So a supply from a member state to any of the five is an **export**, zero-rated
under art. 146. The Commission puts it plainly: goods leaving to these territories
are subject to export formalities and are treated as transported outside the EU.

France's 8.5% is a **national** VAT levied under its own law on supplies made
*within* those territories, or on *importation into* them — paid by a seller
established there, or by the importer at the border. It is not an obligation on an
EU seller selling across the border.

## This is the same shape the engine already gets right

The Canary Islands charge IGIC. Ceuta and Melilla charge IPSI. Neither is EU VAT,
and the engine correctly stops charging Spanish VAT and reports the local tax by
name instead — a fix made earlier the same day, on exactly this reasoning.

The failure here was not applying it to France.

## What remains genuinely open

**Corsica.** Unlike the overseas departments, Corsica IS inside France's VAT
territory, and it applies special rates under CGI art. 297 — TEDB reports 0.9% and
13%, with 2.1% and 10% on other categories. A Corsican supply currently takes
mainland France's rates, and for the categories with a special rate that is wrong.
It has no entry in `StaticEuTerritories` at all.

That one is a real gap, and it is the opposite case: a territory inside the VAT area
whose own rates the engine does not carry — the same shape as Madeira and the
Azores, which are now handled.

**A seller established in one of the five.** If this engine ever has to price a
local supply inside Martinique, that is a domestic French-overseas regime and not an
EU VAT question. Nothing about the current behaviour blocks adding it later.

## Sources

- Council Directive 2006/112/EC, art. 6(1) and art. 146
- CGI art. 294, 296, 296 bis, 297, 298 septies
- BOI-TVA-GEO-20-10 and BOI-TVA-GEO-20-40, French tax administration doctrine
- European Commission, *Territorial scope* (VAT Directive guidance)
- TEDB `REGION` heading, captured 2026-08-14
