---
title: Country coverage
weight: 25
description: Which jurisdictions the engine supports, the rate data behind each, and — honestly — which it does not yet cover and why.
---

# Country coverage

The engine's principle is **own the calculation logic, source only the rate data**
— and **omit a country rather than ship a rate we cannot stand behind**. This
section documents exactly what is supported, with the authoritative source and a
confidence level per jurisdiction, and lists the jurisdictions we deliberately do
*not* yet cover with the reason.

- [What the register covers](the-register.md) — the 80 jurisdictions, how a rate is found, and where the gaps are.
- [Supported jurisdictions](supported.md) — regime, rate, source, confidence.
- [Not yet supported](not-yet-supported.md) — researched but omitted, and why.
- [US SaaS taxability](us-saas-taxability.md) — the curated, cited per-state SaaS map, kept as a reference for what the register answers.
- [US economic-nexus thresholds](us-nexus-thresholds.md) — the cited per-state *Wayfair* threshold table, kept as a reference for what the register answers.

Two boundaries to keep in mind:

- **Rate data vs regime coverage.** "Supported" means the engine models the
  jurisdiction's *logic* (place of supply, reverse charge, rate application). The
  rate *number* comes from the register, which must be synced before the engine
  will price anything — see [The register](../getting-started/the-register.md).
- **Tax rate ≠ e-invoicing.** This engine calculates tax. It does **not** handle
  mandatory e-invoicing / clearance mandates (Brazil NF-e, India IRN, Italy SdI,
  Mexico CFDI, Poland KSeF, Saudi ZATCA, …) — that is a separate invoicing concern.
