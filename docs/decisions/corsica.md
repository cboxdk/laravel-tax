---
title: Corsica
weight: 54
description: Measured 2026-08-14 — Corsica's special rates are per-operation, not a level substitution, so the territory mechanism that handles Madeira does not fit.
---

# Corsica

**Date:** 2026-08-14 · **Status:** measured, partially modellable, not built

## Why this is not the Madeira case

Madeira and the Azores set their own value for **each of Portugal's three rate
levels**, so the engine substitutes by level: whatever the mainland charges, look up
what the territory charges instead. That mechanism now works.

Corsica does not work that way. It is inside France's VAT territory and its standard
rate **is** the mainland's 20%. What CGI art. 297 provides is a list of **specific
operations** taxed at special rates:

| Rate | Operations (CGI art. 297) |
| --- | --- |
| 0.90% | Art. 281 quater and sexies — the first 140 performances of a newly created or classically re-staged dramatic, lyrical, musical or choreographic work; circus; sales of live butchery livestock and pork products to non-taxable persons |
| 2.10% | Art. 298 octies — composition and printing of periodical publications, and supplies of information by news agencies |
| 10% | Real estate works under art. 257; sales of agricultural equipment delivered in Corsica per ministerial order; furnished or serviced accommodation other than art. 279; sales of alcoholic drinks for consumption on the premises |
| 13% | Certain petroleum products delivered in Corsica |

There is no "Corsica's reduced rate". There is a list, and everything not on it takes
the mainland rate.

An earlier note in this repository called Corsica "mechanical work" once Madeira was
done. That was wrong: the mechanism does not transfer, because the two territories
derogate along different axes.

## What is and is not reachable at this engine's granularity

The engine classifies a supply by `TaxClass`, and some of art. 297's operations map
cleanly onto one while others are narrower than any class:

| Operation | Class | Reachable? |
| --- | --- | --- |
| Petroleum products at 13% | `fossil_fuel` | **Yes** — mainland charges 20%, so this is a real difference the engine gets wrong today |
| Furnished accommodation at 10% | `accommodation` | Yes, but the mainland rate is already 10% — no difference to capture |
| Alcoholic drinks consumed on the premises at 10% | — | **No.** `wine` does not distinguish on-premises consumption from retail sale, and the distinction is what the rate turns on |
| Periodical composition and printing at 2.1% | — | **No.** This is a B2B printing service, not the periodical; `periodical` is the wrong class and using it would tax the wrong supply |
| Theatrical performances, circus, live livestock at 0.9% | — | **No.** Narrower than any class, and bounded by a count (the first 140 performances) that no rate table can carry |

So of five operations, **one** is both reachable and a real correction.

## Recommendation

Model the 13% on petroleum products, and nothing else, when a Corsican postal range
(20xxx) is resolved. Leave the rest, and say in the docs that Corsica's special
rates are only partially modelled — rather than implying full coverage from an entry
that carries one line of a five-line article.

The alternative — splitting `TaxClass` until every art. 297 operation has one — buys
Corsica at the cost of a taxonomy shaped by one island's derogation, and would still
not carry "the first 140 performances".

## Sources

- CGI art. 297, and arts. 257, 279, 281 quater, 281 sexies, 298 octies it refers to
- BOI-TVA-GEO-10-10, *Taux de la TVA applicables en Corse*
- TEDB `REGION` heading, captured 2026-08-14
