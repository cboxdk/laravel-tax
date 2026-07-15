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

A broad set of national VAT/GST economies almost certainly slot into the existing
`NationalTaxRegime` (destination tax + B2B reverse charge), but the specific
**rate figures** from secondary aggregator tables did **not** survive adversarial
verification, so we have not written them in. They will be added once each rate is
confirmed against the national tax authority:

> China, Japan, South Korea, Taiwan, Indonesia, Vietnam, Thailand, Philippines,
> Malaysia (note: SST, not a clean VAT), Saudi Arabia, United Arab Emirates,
> Bahrain, Oman, Israel, Turkey, South Africa, Nigeria, Kenya, Egypt, Morocco,
> Russia, Ukraine, Colombia, Chile, Argentina, Peru.

**Why omitted:** the engine models these regimes fine, but shipping an unverified
rate would be worse than shipping nothing — a wrong tax amount is a real liability
for the adopter. Coverage is added per country as its rate is confirmed.

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
