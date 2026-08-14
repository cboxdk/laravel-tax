---
title: Return data
weight: 5
description: Aggregate assessments into per-jurisdiction return totals for filing.
---

# Return data

The `ReturnAggregator` rolls a set of assessments up into return-data — net and
tax totals per **taxing jurisdiction** and currency — ready for filing. The engine
owns the aggregation; submitting to each authority (e.g. via the UK MTD API or an
EU OSS portal) is the host's concern.

A taxing jurisdiction is a **country plus, where the tax is sub-federal, its
subdivision**. So a US set produces a line **per state** and an EU OSS set produces
a line **per member state** — the granularity a filing actually needs — rather than
collapsing everything to the country.

```php
use Cbox\Tax\Contracts\ReturnAggregator;

$return = app(ReturnAggregator::class)->aggregate($assessments);

foreach ($return->lines as $line) {
    $line->country->value;                       // "US"
    $line->subdivision?->value;                  // "US-CA" (null for national lines)
    $line->currency;                             // "USD"
    (string) $line->net->getAmount();            // "200.00"
    (string) $line->tax->getAmount();            // "14.50"
    $line->count;                                // number of supplies
}

$return->lineFor(new CountryCode('DK'), 'EUR');                          // national line
$return->lineFor(new CountryCode('US'), 'USD', new SubdivisionCode('US-CA')); // per-state line
```

A bare-country `lineFor()` matches only a national (subdivision-less) line — a US
state line is not returned unless you pass its subdivision.

Money of different currencies is never mixed — each currency is its own line, so a
jurisdiction billed in more than one currency yields more than one line. Summing
uses exact `Money::plus`, so aggregation introduces no rounding remainder.

## Per-authority totals

A jurisdiction total is not what gets remitted in a stacked state. Kansas is paid
as a state figure and a city figure, to different authorities, and a line that only
said "US-KS: $81.25" left you to rebuild the split by hand from the individual
assessments — the one piece of arithmetic on a signed return that should never be
done twice.

```php
foreach ($line->authorities ?? [] as $authority) {
    $authority->level;                       // JurisdictionLevel::State | County | City | …
    $authority->label();                     // the name, else the code, else the level
    (string) $authority->tax->getAmount();   // what this authority is owed for the period
}
```

**`authorities` is null when the split cannot be known**, and that is the only safe
reading of it — treat it as missing data, never as "one authority took everything".
It is null when any taxed supply in the period arrived without a rate breakdown, or
carried an authority share with neither a code nor a name: you cannot remit to an
authority you cannot identify.

That is stricter than the equivalent on a single document, deliberately. A document
breakdown is there to be read; a return is signed and paid from. A partial roll-up
would still add up to a plausible figure, which is exactly what makes the omission
invisible.

The jurisdiction-level `net`, `tax` and `count` on the line stay correct either
way — only the split goes unreported.
