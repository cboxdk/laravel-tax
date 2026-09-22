---
title: Seller registrations
weight: 5
description: How the selling entity states where it is established, where it is registered, under which scheme and for which period — and what each of those decides.
---

# Seller registrations

Every assessment carries the selling entity, as `SellerRegistrations`. In a
multi-tenant application each tenant is a different entity with different
registrations, so this travels **with the query**, never in configuration.

```php
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Regime\UsSalesTaxRegime;
use Cbox\Tax\ValueObjects\OssStatus;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;

$seller = new SellerRegistrations(
    establishment: new CountryCode('DK'),
    registrations: [
        // A foreign VAT/GST number: the seller collects there.
        new SellerRegistration(new CountryCode('NO')),

        // A US state permit. This IS nexus — see below.
        new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-TX')),

        // A Canadian province's PST/QST permit, which GST registration is not.
        new SellerRegistration(new CountryCode('CA'), new SubdivisionCode('CA-BC')),

        // A scheme registration: one number, the whole Union.
        new SellerRegistration(new CountryCode('IE'), scheme: 'ioss'),

        // A state's flat-rate election for remote sellers, from the day it took effect.
        new SellerRegistration(
            new CountryCode('US'),
            new SubdivisionCode('US-TX'),
            scheme: UsSalesTaxRegime::REMOTE_ELECTION_SCHEME,
            validFrom: new DateTimeImmutable('2026-01-01'),
        ),
    ],
    oss: new OssStatus(registered: true),
);
```

Nothing here is inferred. A seller that states nothing is a seller established in
one country and registered nowhere else, and that is priced as exactly that.

## Establishment decides the regime's questions; registration decides collection

**`establishment`** is where the selling entity belongs. It settles whether a supply
is cross-border — which is what a reverse charge turns on — and, inside the EU,
whether the seller is on the Union's own rules. Establishment implies registration
at home: a Danish company does not state a Danish VAT number to charge Danish VAT.

**`registrations`** are everywhere else it has a number. Outside the United States,
a supply that would otherwise be taxed at destination is returned as
`NotRegistered` when the seller is neither established nor registered there:

```php
// A Danish shop selling to a Norwegian consumer, with no Norwegian registration.
$assessment->treatment;            // TaxTreatment::NotRegistered
$assessment->tax->getAmount();     // 0.00
```

That is not "no tax was due". Norwegian VAT is due — it is collected at the border
from the buyer, because this seller has no number to remit it under. Add a
`SellerRegistration` for Norway and the same query charges 25%. The distinction
matters on a return, which is why `NotRegistered` is its own treatment rather than a
zero: see [marketplace facilitator](marketplace-facilitator.md) for the four zeros
that mean different things.

Two ways in are treated as registration across the whole Union, because that is what
they are: an `OssStatus` with `registered: true`, or a registration whose `scheme` is
`oss` or `ioss`, wherever it is filed. Both are read on the supply's date.

## Sub-federal permits: US nexus and Canadian PST

A registration with a **`subdivision`** is a permit in that state or province, and it
is the only thing that establishes an obligation there.

- **United States.** The seller must hold the state's permit, or the supply comes
  back `NotRegistered` with the state's economic-nexus threshold quoted in the
  reason — see [US economic-nexus thresholds](../coverage/us-nexus-thresholds.md).
  The permit also gates the state's delivery-charge rules: without it there is no
  charge to exclude freight from.
- **Canada.** GST and HST are one federal registration, so a seller registered in
  Canada charges Ontario's 13%. A **PST or QST is the province's own**, and a seller
  that does not hold it charges the federal 5% in British Columbia, Saskatchewan,
  Manitoba and Quebec rather than the combined rate. State the province permit to
  collect it.

A registration for the country alone (`new SellerRegistration(new CountryCode('US'))`)
is not a permit in any state and grants nexus nowhere.

## Schemes

`scheme` records *how* the seller is registered, not merely that it is. Three values
are read by the engine, and the rest are yours to keep for your own bookkeeping:

| Scheme | Read by | What it does |
| --- | --- | --- |
| `oss`, `ioss` | the collection gate | Collects across all 27 Member States from one number |
| `remote-election` | `UsSalesTaxRegime` | Prices a state's flat-rate remote-seller programme instead of resolving the rate — Texas's single local rate, Alabama's SSUT |

A `remote-election` scheme needs the **subdivision** as well; an election is a state's
programme, not a country's. It is also skipped for a supply shipped from inside the
destination state: those programmes exist for remote sellers, and in-state presence
is priced the ordinary way.

## Registrations have a lifetime

`validFrom` and `validUntil` are inclusive of their day, and null means open-ended.
They are checked against the **supply's tax point**, not today.

This is what makes a backfill correct. A seller registered in Texas on 1 March did
not owe Texas tax in February; without the window, recalculating last year's invoices
applies today's registrations to them and produces a return for a period the seller
was not registered in. The same applies in reverse to a number that lapsed. See
[dates](dates.md) for everything else resolved on the tax point.

```php
new SellerRegistration(
    new CountryCode('US'),
    new SubdivisionCode('US-TX'),
    validFrom: new DateTimeImmutable('2026-03-01'),
    validUntil: new DateTimeImmutable('2026-12-31'),
);
```

## The signals, and what reads each one

| Signal | Read by | Decides |
| --- | --- | --- |
| `establishment` | every regime | Cross-border, reverse charge, EU-established treatment |
| `registrations[].country` | the collection gate | Whether the seller collects in that country at all |
| `registrations[].subdivision` | US and Canadian regimes | Nexus in a state; a province's PST |
| `registrations[].scheme` | the collection gate, `UsSalesTaxRegime` | One-stop coverage; a flat-rate election |
| `validFrom` / `validUntil` | all of the above | Whether it was in force on the supply date |
| `oss->registered` | the collection gate | One-stop coverage of the Union |
| `oss->thresholdExceeded` | `EuVatRegime` | Destination sourcing under Art. 59c |

The two `OssStatus` flags are **not** interchangeable. `thresholdExceeded` says the
micro-business relief no longer applies, which moves the place of supply to the
customer's Member State; it does not say the seller has a number to collect with. A
seller over the threshold, not OSS-registered and not established in the Union is
therefore sourced at destination and then returns `NotRegistered` — which is the
honest answer, and the remedy is to register.

## Asking what the seller holds

`SellerRegistrations` answers the same questions the engine asks, so an application
can gate its own checkout on them:

```php
$seller->isEstablishedIn(new CountryCode('DK'));                          // bool
$seller->isRegisteredIn(new CountryCode('NO'), $supplyDate);              // bool
$seller->isRegisteredInSubdivision(new SubdivisionCode('CA-BC'), $date);  // bool
$seller->holdsSubdivisionScheme(new SubdivisionCode('US-TX'), 'remote-election', $date);
$seller->holdsUnionScheme($date);                                         // OSS or IOSS
$seller->hasScheme('ioss', $date);                                        // any one scheme
```
