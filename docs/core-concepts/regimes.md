---
title: Regimes
weight: 2
description: The tax regimes shipped, how they are selected, and reverse-charge behaviour.
---

# Regimes

A regime is selected by the `regimeModule` key on the buyer jurisdiction's tax
profile (from `laravel-geo`).

## Shipped

- **`EuVatRegime`** (`eu-vat`) — destination VAT at the customer's Member State
  rate; intra-EU B2B supplies to a validated customer reverse-charge.
- **`NationalTaxRegime`** (`uk-vat`, `ch-vat`, `no-vat`, `au-gst`, `nz-gst`,
  `mx-iva`) — single national-rate VAT/GST with a cross-border B2B reverse charge.

Both share `DestinationTaxRegime`: a cross-border B2B supply to a tax-ID-validated
customer reverse-charges; everything else is taxed at the place-of-supply rate.

## Reverse charge

Reverse charge applies only when the supply is **cross-border** (the selling
entity is not established in the buyer's country), the customer is a **business**,
and their tax ID is **validated** (`customerTaxIdValidated: true`) — because
zero-rating legally hinges on a valid customer VAT/registration number. Otherwise
destination tax is charged.

## Not yet shipped

The sub-federal regimes (`us-sales-tax`, `ca-gst`) are intentionally absent until
their rate/boundary data and (for the US) rooftop geocoding land. A jurisdiction
whose regime is not registered raises `UnsupportedJurisdiction` — it is never
guessed.
