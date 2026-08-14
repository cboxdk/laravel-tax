---
title: EU special territories
weight: 7
description: Ten places inside a Member State where its VAT rules do not simply apply.
---

# EU special territories

A country code is not always enough to know which tax applies. Ten territories sit
inside an EU Member State and outside its VAT rules, and they fail in two
completely different ways.

## Outside the VAT area

The Canary Islands, Ceuta, Melilla, Åland, Livigno, Campione d'Italia, Büsingen,
Heligoland and Mount Athos are in the European Union but **not in its VAT
territory**. A supply into one of them from a Member State is an **export**.

This is not a rate error. Charging 21% Spanish VAT on a delivery to Tenerife
invents a liability that does not exist, while the customer separately owes IGIC
that nobody collected. No rate table can express "this is not our tax" — only a
place can, which is why these are modelled as territory rather than as a rate
override.

```php
$assessment = $tax->assess(new TaxQuery(
    // ...
    place: $geo->find(new CountryCode('ES')),
    postalCode: '38001',                      // Santa Cruz de Tenerife
));

$assessment->treatment;   // TaxTreatment::ZeroRated
$assessment->reason;      // "…Canary Islands lies outside the EU VAT area…IGIC applies there…"
```

The reason names the tax that *does* apply there. A bare zero would leave the
caller to work out whether nothing is owed or something is owed to somebody else.

**What this does not cover.** A supply *into* the territory is an export and
zero-rated — the overwhelming majority of what an EU seller does. A supply *by* a
business established in the territory to a customer there is not EU VAT at all; it
is IGIC or IPSI, and this engine does not compute either. The two cannot be told
apart from a country code and a postcode, so the reason states the local tax
rather than implying the answer covers both.

## Inside the VAT area, with their own rates

The Azores charge **16%** and Madeira **22%**, where mainland Portugal charges 23%.
They are in the VAT area; they simply set their own rates.

```php
$tax->assess(/* … postalCode: '9500-001' */)->rate->percentage;  // "16"
```

Only the **standard** rate is substituted, and the rate carries
`Confidence::Derived` rather than `Authoritative` to say where it came from. The
territory map is stable for decades; rates are not, and a snapshot of moving
figures is exactly what this package avoids elsewhere.

A **reduced-rate** supply into these regions therefore keeps the mainland band,
with the shortfall named in the reason. That is a deliberate choice between two
wrongs: Madeira's reduced rate is 5% against the mainland's 6% and the Azores' is
4%, so falling back **over**-charges by a point or two — recoverable — where
refusing the line would lose the sale.

## Why postal codes

Because nothing else is available. The addressing reference data carries **no
subdivisions at all** for Portugal, Finland, France or Greece, so the Azores,
Madeira, Åland and Corsica cannot be named that way. Every one of these
territories has had its own postal range for decades.

`postalCode` on `TaxQuery` is optional and null is common — most callers have none
at hand, and the national rules are right for the overwhelming majority of
addresses. What the engine must not do is treat a *missing* postcode as proof of
mainland, and `EuTerritories::for()` returns null for "cannot place" rather than
"ordinary".

France's overseas départements need no postcode: Guadeloupe, Martinique, French
Guiana, Réunion and Mayotte each carry their own ISO 3166-1 country code, and the
geo repository already resolves them as outside the EU.

## Replacing the map

`EuTerritories` is a contract like any other. The shipped `StaticEuTerritories` is
a snapshot of Article 6 of the VAT Directive plus the postal ranges; bind your own
to override it.

```php
$this->app->singleton(EuTerritories::class, MyTerritories::class);
```
