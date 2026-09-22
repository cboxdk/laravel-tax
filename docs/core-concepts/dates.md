---
title: Dates
weight: 6
description: The tax point, the reporting date, and why an assessment resolves against one date throughout.
---

# Dates

Tax is dated law. Rates change, categories move in and out of tax, exemption
certificates expire. An engine that only ever answers "as of now" cannot reissue an
invoice, cannot restate a period, and cannot be audited — and the failure is quiet,
because the answer still looks like a number.

## Two dates, because they do two jobs

```php
new TaxQuery(
    // ...
    suppliedAt: new DateTimeImmutable('2026-12-30'),   // when the supply happened
    reportedOn: new DateTimeImmutable('2027-01-03'),   // which return it lands in
);
```

**`suppliedAt` is the tax point.** It decides which law applies: which rate was in
force, whether the category was taxable, whether the buyer's exemption was valid
that day. Null means today, which is what an ordinary live sale wants.

**`reportedOn` decides which return period the supply is filed in.** It defaults to
the tax point, and is only worth setting when the two genuinely differ — goods
supplied on 30 December and invoiced on 3 January are rated at December's rate
while national rules may put them in either period. One date cannot do both jobs,
and collapsing them means either mispricing the supply or misfiling it.

Both are echoed on the assessment as `taxPoint` and `reportedOn`, so an audit can
see what the engine actually resolved against rather than inferring it.

## One date, everywhere in the assessment

The tax point is threaded through **every** dated lookup for that supply:

| Resolved on the tax point | Where the dated data lives |
| --- | --- |
| the rate | `TaxRateSource::rateFor($jurisdiction, $category, $at)` |
| product taxability | `ProductTaxability::determine($jurisdiction, $category, $amount, $at)` |
| the buyer's exemption validity | `TaxExemption` validity window |
| the seller's registrations | `SellerRegistration` validity window (`validFrom`/`validUntil`) |
| marketplace, holidays and sourcing | dated rules in the register |

The seller's side matters for the same reason as the buyer's: recalculating last
year's invoices with today's registrations produces a return for a period the seller
was not registered in. See [seller registrations](seller-registrations.md).

This is a correctness property, not a convenience. An assessment priced with one
year's rate and another year's taxability is internally inconsistent, and a state
that started taxing a category last year would otherwise have the engine charge tax
on a supply made before the law existed.

```php
// A state that exempted groceries until 2025-12-31 and taxes them from 2026-01-01.
$grocery = fn (string $date): TaxQuery => new TaxQuery(
    amount: Money::of('100.00', 'USD'),
    pricing: Pricing::Exclusive,
    place: $kansas,
    customer: CustomerType::Consumer,
    seller: $registrations,
    category: TaxClass::Groceries,
    suppliedAt: new DateTimeImmutable($date),
);

$calculator->assess($grocery('2025-06-15'))->treatment;  // TaxTreatment::Exempt
$calculator->assess($grocery('2026-06-15'))->treatment;  // TaxTreatment::Standard
```

(`$query->on()` reads the resolved tax point back — it is the getter the engine
itself uses, not a builder.)

Window boundaries are **inclusive on both sides**: 31 December is the last day of
the old rule and 1 January the first day of the new one. An off-by-one here is a
whole day of invoices priced wrong.

## What a source does with a date it cannot honour

`RegisterRateSource` and `RegisterTaxability` select rate and rule windows on the
supply date. A rate source that cannot answer for that date returns `null`, so the
engine can refuse rather than use an unrelated rate.

Boundary artifacts are snapshots of the selected register release. They do not
carry effective-date windows, so the shipped boundary resolver cannot reconstruct
an annexation from a newer snapshot. Select an appropriate historical release, or
bind a resolver with dated boundaries when that distinction matters.

`NexusThresholds::for()` and `SourcingRules::for()` accept an optional date; the US
regime supplies the tax point. Published rounding and delivery rules use that date
as well. Nexus still returns advisory figures rather than determining whether a
seller's cumulative activity crossed a threshold. A capture-floor date establishes
available coverage, not an earlier legal commencement that the engine can infer.
