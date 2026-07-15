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

A round of primary-source verification moved Taiwan, UAE, Saudi Arabia, Bahrain,
Oman, Türkiye, Chile, Indonesia, Vietnam and the Philippines into
[supported](supported.md). The following remain omitted because their rates were
**not** confirmed against a primary or dated authoritative source — the aggregator
compilations were refuted in verification. They will be added per country as each
rate is confirmed against the national tax authority:

> China (genuinely multi-rate 13/9/6 — no single digital rate confirmed), Japan,
> South Korea, Thailand, Israel, South Africa, Nigeria, Kenya, Egypt, Morocco,
> Russia, Ukraine, Colombia, Argentina, Peru.

**Why omitted:** the engine models these regimes fine, but shipping an unverified
rate would be worse than shipping nothing — a wrong tax amount is a real liability
for the adopter.

## Not implemented (no VAT)

**Qatar** and **Kuwait** have not implemented VAT — there is no rate to charge.
(Qatar is trending toward a possible ~2027 rollout.)

## Malaysia — split SST, needs a dedicated regime

Malaysia is **not** a clean VAT: it runs a Sales and Service Tax (SST) — 10% sales
tax on goods, 8% service tax on prescribed services — and, unusually, foreign
digital service providers charge **both B2C and B2B with no reverse-charge
carve-out**. The generic national regime (which reverse-charges cross-border B2B)
would be wrong here, so Malaysia is held until a dedicated SST regime is built.

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
