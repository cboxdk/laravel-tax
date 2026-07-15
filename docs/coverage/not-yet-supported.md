---
title: Not yet supported
weight: 2
description: Jurisdictions researched but deliberately omitted until we can ensure the data quality — with the reason for each.
---

# Not yet supported

We would rather omit a jurisdiction than ship a rate or rule we cannot stand
behind. The following were researched but are **not yet shipped**, each with the
reason. They are tracked for inclusion once the data is verified against primary
sources.

## Awaiting primary-source rate verification

Successive primary-source verification rounds moved Taiwan, UAE, Saudi Arabia,
Bahrain, Oman, Türkiye, Chile, Indonesia, Vietnam, the Philippines, **Japan, South
Korea, Thailand, Ukraine and Malaysia** into [supported](supported.md). The
following remain omitted:

**Rate known, destination-taxation mechanics not yet verified** — their standard
rate is confirmed (dated Big-Four), but whether/how they tax *foreign digital
suppliers at destination* (and the registration threshold) was not independently
verified, so they are held until that mechanic is confirmed:

> Israel (18%), South Africa (15%), Nigeria (7.5%), Kenya (16%), Egypt (14%),
> Morocco (20%), Colombia (19%), Peru (18%).

**Genuinely not a clean single-rate regime:**

> **China** — VAT is tiered 13/9/6% (6% is the modern/e-services band) with no
> single "digital services" rate; the unified VAT Law effective 1 Jan 2026 keeps
> the tiered structure. Omitted rather than hard-code a judgment-call 6%.
> **Argentina** — national IVA (21%) is entangled with sub-national *ingresos
> brutos*, so it is not cleanly shippable as a single national rate.

**Why omitted:** the engine models these regimes fine, but shipping an unverified
rate or mechanic would be worse than shipping nothing.

## Not implemented (no VAT)

**Qatar** and **Kuwait** have not implemented VAT — there is no rate to charge.
(Qatar is trending toward a possible ~2027 rollout.)


## Pakistan — partial data

Structurally a federal-goods (FBR, ~18%) vs provincial-services split. Only the
**Sindh** service rate (15%, eff. 1 Jul 2024) and the federal goods rate are
confirmed; the **Punjab, Khyber Pakhtunkhwa and Balochistan** provincial service
rates are not yet verified. **Why omitted:** a Pakistan regime that only covers one
province would misrepresent coverage; it is held until all provincial rates are
confirmed.

## Brazil — genuinely hard, needs local/commercial data

The current system stacks **ICMS** (VAT across 27 states, with tax substitution),
**ISS** (service tax across ~5,570 municipalities), **IPI** and **PIS/COFINS**. The
municipal ISS and state ICMS rate/rule data are too granular to own and must be
sourced commercially or locally. **Why omitted:** we will not fabricate ~5,570
municipal rates. The 2023 reform (EC 132/2023, LC 214/2025) replaces these with a
dual VAT (**CBS** federal + **IBS** sub-national), phasing in 2026–2033 — a far more
ownable structure that we intend to support as it takes effect.

## Out of scope entirely: e-invoicing / clearance

This engine calculates tax rates. Mandatory e-invoicing / real-time clearance
mandates — Brazil NF-e/NFS-e, India IRN, Italy SdI, Mexico CFDI, Poland KSeF, Saudi
ZATCA, and others — are a **separate invoicing concern** and are not, and will not
be, part of the tax-rate engine.
