---
title: Seller-scoped territories
weight: 55
description: Measured 2026-08-20 — the Greek islands' 30% reduction and Austria's Jungholz/Mittelberg 19% key on where the seller is established, so the right territory entry is no entry.
---

# Seller-scoped territories

**Date:** 2026-08-20 · **Status:** measured; deliberately no `EuTerritories`
entry for either

Two territorial rates exist that this engine's territory map does not carry: the
Greek Aegean islands' 30% reduction and the 19% standard rate in Austria's
Jungholz and Mittelberg enclaves. Both were investigated for an entry, and for
both the finding is the same: **the rate follows the seller's establishment, not
the destination** — so for the cross-border supplies a territory lookup exists
to price, the national rate is already the correct answer, and an entry would
under-charge it.

## The claim a territory entry makes

`EuTerritories::for()` answers from a destination — a country and a postcode.
An own-rates entry therefore asserts: *a supply landing here takes these rates,
whoever the seller is.* Madeira and the Azores satisfy that; these two regimes
do not.

## Austria — Jungholz and Mittelberg (19%)

§ 10 Abs. 4 UStG 1994 lowers the rate to 19% for supplies **effected in** the
two enclaves **by entrepreneurs with a residence, seat, habitual abode or
establishment there** — with carve-outs that send vehicle sales and supplies to
mainland-Austrian establishments back to 20%. A seller in Vienna or Copenhagen
charges 20% on a delivery into Jungholz. An entry keyed on the enclaves'
postcodes would shave a point off every cross-border invoice, wrongly.

## Greece — the Aegean islands (17 / 9 / 4)

From 2026-01-01 the regime is art. 26 of the Greek VAT Code (ν. 5144/2024, as
amended by art. 11 of ν. 5246/2025): παρ. 4 keeps Lesvos, Kos, Samos and Chios
conditioned on migrant-reception structures operating there; παρ. 4Α extends
the 30% reduction to the islands of the North Aegean region, Samothrace and the
Dodecanese with up to 20,000 inhabitants; παρ. 4Β excludes tobacco products and
means of transport; παρ. 5 covers services. The reduction turns 24/13/6 into
17/9/4 (and the 4% level into 3%).

The conditions are the decisive part, spelled out in AADE circular Ε.2113/2025
(31.12.2025): for goods, the reduced rates require the goods to be **on the
islands and sold by a supplier established there**, dispatched to a **taxable
person or non-taxable legal person** established there, or imported by island
residents. The circular's own Παράδειγμα 6 prices a mainland seller delivering
an appliance to a **private individual** on Kalymnos at the **normal** rate.
Services take the reduced rates only when the supplier is established on the
islands *and* the service is materially executed there.

So the scenario a destination lookup answers — a seller somewhere else charging
a consumer on the island — is exactly the scenario the regime excludes. The
2026 expansion of eligible islands changed nothing for this engine, because the
scoping is what decides, and the scoping did not move.

## What no-entry gets right, and what it cannot

- A cross-border or mainland seller invoicing a consumer on Chios or in
  Jungholz gets the national rate. **Correct**, per the regimes' own terms.
- A seller *established on* a qualifying island selling locally is entitled to
  17/9/4, and this engine will price 24/13/6 — an over-charge, visible on the
  invoice, in the recoverable direction. Modelling it would need the seller's
  establishment as an input to territory resolution, not just the destination.

## What would reopen this

1. **Greece unconditions the rates** — if a future amendment extends the
   reduction to consumer dispatches regardless of seller establishment, the
   islands become genuine destination territories and belong in the map.
2. **Establishment-aware sellers** — a consumer whose sellers are established
   on the islands (or in the enclaves) and who needs local pricing; the entry
   point would be seller establishment on `TaxQuery`, not a postal range.

## Sources

- UStG 1994 § 10 Abs. 4 (RIS, Bundesrecht konsolidiert)
- Greek VAT Code ν. 5144/2024 art. 26, as amended by ν. 5246/2025 art. 11
  (in force 2026-01-01); Α.1150/2021 (Β΄ 2828) remains in force for the four
  παρ. 4 islands
- AADE Ε.2113/2025 (31.12.2025, ΑΔΑ: ΨΨΑ046ΜΠ3Ζ-ΚΘΤ), esp. Παράδειγμα 6 and
  section III on services
- TEDB `REGION` heading, captured 2026-08-14: AT 19% "Jungholz, Mittelberg";
  GR 17% "The Aegean Islands of Leros, Lesvos, Kos, Samos and Chios"
