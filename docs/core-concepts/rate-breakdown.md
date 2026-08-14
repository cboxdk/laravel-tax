---
title: Rate breakdown
weight: 4
description: How an assessment splits its tax across the state, county, city and special-district authorities that levy it — and why the split is allocated rather than recomputed.
---

# Rate breakdown

A US rooftop rate is not one number, it is a stack: Kansas City's 9.125% is the
state's 6.5%, plus Wyandotte County's 1%, plus the city's 1.625%. Charging the
combined figure is only half the obligation — the seller then remits **to each
authority separately**, and a return needs to know how much of the tax belongs to
whom.

That split is knowable only where the rate is stacked. Once the percentages are
summed the information is gone, and no consumer can recover it from the total.
So the source keeps it: a stacked rate carries its
[`RateComponent`](#what-a-source-emits)s, and the engine turns them into a
[`TaxBreakdown`](#reading-a-breakdown) on the assessment.

## Reading a breakdown

```php
$assessment = $tax->assess($query);

foreach ($assessment->breakdown?->lines ?? [] as $line) {
    printf(
        "%-16s %6s%%  %s\n",
        $line->label(),        // "209", "ALAMEDA", or the level when unnamed
        $line->percentage,     // 1.625
        $line->tax->getAmount()
    );
}
```

```
state              6.5%  6.50
209                  1%  1.00
36000            1.625%  1.63
```

`TaxBreakdown` also answers the two questions a filing asks:

```php
$assessment->breakdown->total();                              // === $assessment->tax
$assessment->breakdown->atLevel(JurisdictionLevel::State);    // the state line(s)
```

## The parts sum to the whole

`total()` equals the assessment's tax **exactly**, and that is a guarantee, not a
coincidence. The engine allocates the real total across the components rather than
applying each authority's rate to the net on its own.

The difference is not academic. On a $1.00 supply in Kansas City:

| | State 6.5% | County 1% | City 1.625% | Sum |
| --- | --- | --- | --- | --- |
| Rate applied per authority | 0.07 | 0.01 | 0.02 | **0.10** |
| Allocated from the real total | 0.06 | 0.01 | 0.02 | **0.09** |

The tax actually charged was $0.09. Recomputing per authority invents a cent that
was never collected, and a return built from those lines does not reconcile with
the invoices behind it.

The remainder goes to the largest fractional shares (the Hamilton method), not to
whichever authority the source happened to list first — list order is an
implementation detail of the source, not a statement about who is owed the odd
cent, and an authority levying 0% must never receive one.

## When there is no breakdown

`breakdown` is `null` whenever the split is not known, and **null means unknown**.
A consumer filing per jurisdiction must treat it as missing data — never as "one
authority takes all of it".

It is null when:

- the rate source did not decompose the rate (a flat national rate, a static
  table, a point-in-polygon service returning one all-in figure);
- the supply was not taxed — reverse-charged, exempt, not-registered, zero-rated;
- a buyer exemption overrode the regime's verdict.

## What a source emits

A `TaxRateSource` populates `TaxRate::$components` when — and only when — it knows
the split. The components **must sum to the rate**; a `TaxRate` whose components do
not reconcile throws `RateComponentsDoNotReconcile` at construction.

That is deliberately strict. A breakdown that is slightly wrong is worse than none
at all: it has exactly the shape of an authoritative split, so it gets remitted on,
and the shortfall surfaces at audit rather than at calculation. A source that
cannot decompose a rate supplies **no** components.

```php
new TaxRate('9.125', RateKind::Standard, 'us-tax-data', components: [
    new RateComponent(JurisdictionLevel::State, '6.5'),
    new RateComponent(JurisdictionLevel::County, '1', '209'),
    new RateComponent(JurisdictionLevel::City, '1.625', '36000'),
]);
```

`code` and `name` are provenance, never invention — a source with no published
name for an authority leaves them null rather than deriving a plausible label.

### What the shipped US source emits

[`UsTaxDatasetRateSource`](../extension-points/rate-sources.md) decomposes by the
state's rate basis:

| Rate basis | Example | Components |
| --- | --- | --- |
| Component | KS, NC, TX | The state share plus one line **per authority** the boundary index matched |
| Combined | CA | Two lines: the state share, and the **aggregate** local remainder |
| — (state-only rate) | any state, no rooftop | None — the state share is the absence of a stack, not a stack of one |
| — (reduced category) | MO grocery | None — a product rule, not a stack of authorities |

A combined-basis record (California's CDTFA place rates) publishes one figure that
already contains the state share, so the per-authority split is not available in
the data. Subtracting the known state share still yields the state/local split that
drives remittance, so it is reported — but the remainder is levelled `local`, not
`city`, because it aggregates every district taxing that address. Attributing it to
the named city would put other authorities' money on the city's line.

## Known limitation: one taxable base

Every line reports `taxableAmount` as the supply's full net, because the engine
applies a single taxable base across the stack.

That is correct for the states modelled today, but it would **not** be for a state
that exempts a category at the state level while its localities still tax it —
Illinois and Missouri both do this for groceries. Supporting that needs a per-level
taxability seam, which does not exist yet, so a per-level base is deliberately not
claimed here.
