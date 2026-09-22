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
  rate; intra-EU B2B supplies to a validated customer reverse-charge. It also
  applies the **Art. 59c €10,000 micro-business threshold** (see below).
- **`NationalTaxRegime`** (`uk-vat`, `ch-vat`, `no-vat`, `au-gst`, `nz-gst`,
  `mx-iva`) — single national-rate VAT/GST with a cross-border B2B reverse charge.

Both share `DestinationTaxRegime`: a cross-border B2B supply to a tax-ID-validated
customer reverse-charges; everything else is taxed at the place-of-supply rate
(overridable per regime — the EU regime overrides it for origin sourcing).

## Collection is gated on the seller's registrations

A regime decides the rate and the treatment. Whether this seller collects it is a
separate question, asked after: outside the United States, a would-be `Standard`
supply comes back **`NotRegistered`** when the seller is neither established nor
registered in the place of supply. Inside the Union an OSS or IOSS registration
covers every Member State, and an EU-established seller stays on the Union's rules.

This catches the common case of a shop selling abroad for the first time: the tax is
due, but it is collected at the border from the buyer, not by a seller with no number
to remit it under. See [seller registrations](seller-registrations.md).

## EU €10,000 micro-business threshold (Art. 59c)

A seller established in a single Member State, **below** the €10,000 combined
cross-border B2C threshold (current or preceding year) and **not** opted into OSS,
charges its **own (origin)** VAT on cross-border B2C supplies to other Member
States; once it opts in or crosses the threshold, the general **destination** rule
applies. The seller supplies these signals on `SellerRegistrations::$oss`
(`OssStatus { registered, thresholdExceeded }`). The two are not interchangeable:
`thresholdExceeded` moves the place of supply, while only `registered` — or an `oss`
/ `ioss` scheme registration — is a number to collect with, which the gate above
asks about separately. Deny-by-default: the engine never
infers turnover, and absent an asserted status it applies destination. B2B
reverse-charge is unaffected.

## Reverse charge

Reverse charge applies only when the supply is **cross-border** (the selling
entity is not established in the buyer's country), the customer is a **business**,
and their tax ID is **validated** (`customerTaxIdValidated: true`) — because
zero-rating legally hinges on a valid customer VAT/registration number. Otherwise
destination tax is charged.

## Sub-federal regimes

- **`UsSalesTaxRegime`** (`us-sales-tax`) — **dataset-backed, state-precision.**
  Destination sourcing with three gates: the state must be resolved (via an
  `AddressGeocoder`), the seller must have **nexus** in it (a registration), and the
  product must be **taxable** there (`ProductTaxability`). Otherwise it returns
  `NotRegistered` or `Exempt` — never a wrong charge; a jurisdiction with no resolved
  state raises `JurisdictionNotResolved`. Rates, per-state taxability, nexus
  thresholds and intrastate sourcing come from the
  [register](../coverage/the-register.md). Address-level precision depends on what
  the register publishes for the state — a street index, a ZIP+4 boundary file, a
  polygon layer or a county name — and absent a resolved locality the state share
  applies, flagged; see [coverage](../coverage/the-register.md).
- **`CaGstRegime`** (`ca-gst`) — Canada has no local sales tax, so a province
  (subdivision) determines the rate: the federal GST plus the province's PST or QST,
  or the harmonised HST in its place. A PST is collected only by a seller registered
  in that province — a `SellerRegistration` with the subdivision; one registered
  federally charges the federal share. A cross-border non-resident B2B supply to a
  registered customer is self-assessed (reverse charge).

A jurisdiction whose `regimeModule` is not registered at all still raises
`UnsupportedJurisdiction` — never guessed.

## Buyer exemptions

Independently of the regime, a query may carry a buyer
[exemption](exemptions.md) certificate. The calculator applies it *after* the
regime's verdict, deny-by-default: a valid exemption covering the taxed
jurisdiction rewrites a would-be `Standard` line to `Exempt`, and leaves
reverse-charge, not-registered and zero-rated outcomes untouched. It works across
every regime because it composes over the assessment, not inside each regime.
