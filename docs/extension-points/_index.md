---
title: Extension points
weight: 30
description: Supply live rate data and add or override regimes.
---

# Extension points

Everything resolves through contracts, so the rate data and the regimes are
replaceable.

- [Product catalogue](product-catalogue.md) — map your SKUs to tax classes once, and get told which products nobody has classified.
- [Rate sources](rate-sources.md) — bind a live `TaxRateSource`.
- [Address geocoding](geocoding.md) — resolve US/CA addresses with Geocodio or your own adapter.
- [Local authorities](local-authorities.md) — resolve US addresses below the state line in the 12 states the shipped dataset cannot.
- [Tax-ID validation](vat-id-validation.md) — validate VAT/registration numbers (VIES, HMRC, ABN).
- [Flat charges](flat-charges.md) — fixed per-supply and per-delivery fees that are not a percentage of anything.
