---
title: EU VAT dataset
weight: 4
description: Reading rates from the compiled cboxdk/eu-tax-dataset — dated windows, published ambiguities, and what the engine does with each.
---

# EU VAT dataset

```php
// config/tax.php
'eu_tax_data' => [
    'location' => env('TAX_EU_DATASET_LOCATION'),
    'ttl' => (int) env('TAX_EU_DATASET_TTL', 86400),
],
```

```dotenv
TAX_EU_DATASET_LOCATION=https://raw.githubusercontent.com/cboxdk/eu-tax-dataset/v0.2.0
```

Unset by default. Configured, it is tried **before** the live TEDB service and
before any hand-built export, because it answers a question a live call cannot:
what a rate was on the date of the supply.

**Pin a tag.** `main` moves, and a rate changing underneath a running system is the
failure the dataset exists to prevent.

## What it gives you

**Dated rates.** The dataset carries a series back to the start of the Commission's
records, so an invoice corrected two years later reprices at the rate that applied
then:

```php
$estonia2023 = $source->rateFor($place, TaxClass::Electronics, new DateTimeImmutable('2023-06-01'));
// 20% — not today's 24%
```

**Reduced bands without a mapping of your own.** The dataset publishes which TEDB
headings each product class asks under, most specific first, so a `book` line finds
France's rate even though France carries no `BOOKS` heading — printed books sit
under `LOAN_LIBRARIES` there.

## The three outcomes

| Situation | Rate | Confidence |
| --- | --- | --- |
| The class maps to a heading with a band | that band | `Authoritative` |
| The class maps to nothing, or to a heading the state does not rate | standard | `Authoritative` |
| The heading is published as **undecided** | standard | `Derived` |

The third is the careful one. Where TEDB rates one heading several ways at once —
Hungarian groceries are 5% for meat and fish and 18% for dairy desserts — the
dataset publishes the ambiguity rather than picking. The standard rate is the safe
fallback, because over-charging is recoverable and silently applying one of several
competing reduced rates is not. But the confidence drops, so a caller billing on it
can see that a better answer exists.

An undecided heading also **stops the search**: falling through to the next heading
would quietly price the supply under one nobody asked about.

## What it refuses

**A date before the records begin.** The archive starts 2016-01-01. Estonia charged
20% in 2015 too, but this dataset never asserted it — answering anyway would put a
rate on an invoice that nothing here stands behind.

**A country it does not carry.** Null, not 0%. The engine denies.

**A remote read whose bytes do not match the manifest.** The published location is a
branch head on a third-party host; one bad push would otherwise reach every
deployment within a cache TTL with nobody having released anything. A local path is
trusted without a manifest — reading your own disk is a deliberate act.

## What it does not do yet

**The special territories, and this source makes the gap more visible.** Madeira's
12/5 and the Azores' 9/4 reduced bands are not in the dataset.

For a STANDARD-rated supply that is handled: the regime substitutes the territory's
own rate after the source answers, marked `Derived`. But for a supply that matches a
reduced band, the regime keeps what the source returned — mainland Portugal's 6% on
a Madeira grocery line, where Madeira charges 5% — and appends a caveat saying the
band may be up to two points high.

That behaviour predates this source and is unchanged by it. What changed is how
often it is reached: before, the EU had few resolved reduced bands, so most
territory supplies fell to the standard path. Now that bands resolve across the
union, a territory line is far more likely to be priced from the mainland.

Two points high is an over-charge, which is recoverable, and the caveat says so on
the assessment. Closing it properly means publishing the territories' own bands in
the dataset. See [EU territories](../core-concepts/eu-territories.md).

## What it does with data it cannot read

The publisher refuses to emit a rate outside 0–100, so a band that will not parse
means verification passed and something else went wrong. Throwing there would fail
every assessment for every country over one bad heading in one, so:

- **An unreadable band** → the standard rate at `LowConfidence`, with the heading
  named in the source string. It does **not** fall through to the next heading:
  that would price the supply under one nobody asked about and look successful.
- **An unreadable standard rate** → null. There is nothing left to fall back to,
  and the engine refuses rather than inventing a percentage.
