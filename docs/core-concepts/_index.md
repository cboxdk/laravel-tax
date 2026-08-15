---
title: Core concepts
weight: 20
description: The engine's architecture and the regimes it ships.
---

# Core concepts

- [Architecture](architecture.md) — how a query flows to an assessment, and the
  own-the-logic / source-the-data boundary.
- [Regimes](regimes.md) — the tax regimes shipped and how they are selected.
- [Exemptions](exemptions.md) — express a buyer certificate natively and get a
  native `Exempt` assessment, applied deny-by-default over the regime's verdict.
- [Rate breakdown](rate-breakdown.md) — splitting an assessment's tax across the
  state/county/city authorities that levy it, with the parts summing exactly to
  the whole.
- [Marketplace facilitator](marketplace-facilitator.md) — when the marketplace is
  liable, the seller charges nothing and still reports the sale.
- [Return data](return-data.md) — aggregating assessments into per-jurisdiction
  and per-authority totals for a filing period.
- [Dates](dates.md) — the tax point vs the reporting date, and why one date drives
  every dated lookup in an assessment.
- [EU special territories](eu-territories.md) — ten places inside a Member State
  where its VAT rules do not simply apply, and why a country code is not enough.
