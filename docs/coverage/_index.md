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

- [Supported jurisdictions](supported.md) — regime, rate, source, confidence.
- [Not yet supported](not-yet-supported.md) — researched but omitted, and why.

Two boundaries to keep in mind:

- **Rate data vs regime coverage.** "Supported" means the engine models the
  jurisdiction's *logic* (place of supply, reverse charge, rate application). The
  rate *number* is data supplied by a `TaxRateSource`; the shipped default rates
  are a starting point, and production should bind an authoritative feed.
- **Tax rate ≠ e-invoicing.** This engine calculates tax. It does **not** handle
  mandatory e-invoicing / clearance mandates (Brazil NF-e, India IRN, Italy SdI,
  Mexico CFDI, Poland KSeF, Saudi ZATCA, …) — that is a separate invoicing concern.
