---
title: Rate sources
weight: 1
description: How the engine gets a rate, what it refuses to guess, and how to put your own source in front.
---

# Rate sources

The engine owns the calculation and sources only the rate **data**, behind one
contract:

```php
interface TaxRateSource
{
    public function rateFor(Jurisdiction $jurisdiction, TaxClass $category, ?DateTimeImmutable $at = null): ?TaxRate;
}
```

Out of the box it is bound to `RegisterRateSource`, which reads the compiled register
off local disk — see [The register](../getting-started/the-register.md) for how that
gets there. It is the only rate source this package ships.

## Having no rate, and being unable to answer

These are different, and the difference decides what a caller does next.

- **`null`** — "I have no rate for this jurisdiction." A normal answer from a source
  with limited scope. A chain moves on to the next source.
- **`RateSourceUnavailable`** — "my endpoint timed out." Not an answer at all. Returned
  as `null` it was indistinguishable from a source that simply does not cover the
  jurisdiction, and the chain moved on and billed from whatever was behind it.
- **`DatasetNotInstalled`** — nothing has been synced, so there is no data to read. The
  message names the command.

A jurisdiction in a regime the store was **not compiled with** refuses rather than
returning null. A store built `--region=eu` knows nothing about Japan, and answering
"no tax in Japan" from an absence would be a fabrication — that refusal is what makes
per-region and per-state opt-in safe to offer at all.

## What a rate carries

`TaxRate` is a percentage plus everything needed to defend it:

- **`kind`** — standard, reduced or zero.
- **`confidence`** — `Authoritative` when the answer is exact, `Derived` when it is the
  best available.
- **`limitedBy`** — a `RateLimit` saying *why* it is not exact and what would close the
  gap. Null when nothing is missing, which is the common case and stays cheap.
- **`components`** — the authorities the rate is made of, where the source can
  decompose it. An **empty** list means "this source cannot decompose this rate", not
  "one authority takes it all".
- **`provenance`** — the release, the window the answer stood on, and the source's own
  snapshot hash. The window matters more than the version: a version alone puts every
  invoice in the blast radius of every republish, while the window's start is what a
  correction actually names.

## Commodity codes

`CommodityRateSource` extends the contract with a code:

```php
$source->rateForCommodity($place, TaxClass::Groceries, 'cn:0401 10');
```

Three rules, and each one was a real defect before it was a rule:

- **The scheme is part of the key.** `32` is a CPA division and a CN chapter, and they
  are about different things. A bare code is read as CN.
- **A code refines the question, never moves it.** The search stays inside the category
  that was asked about, so a customs code scoped to foodstuffs cannot answer a question
  about hotel accommodation.
- **The longest match wins, and a shortened one is an inference.** It comes back
  `Derived` with `RateLimit::ClassificationInferred`, because the register has 95 live
  cases where a chapter and a subheading beneath it disagree.

The answer names the code that decided it: `source` reads `cbox-tax:cn:040110`.

## Local authorities

For an address below the state line, the rate source consults
`LocalAuthorityResolver` — see [Local authorities](local-authorities.md). It is bound
to the register's own boundary resolver, and **every authority that applies is summed
or none of them**: a rate short by one authority's share is an under-charge stamped
authoritative, which is the one outcome this package works hardest to prevent.

## Putting your own source in front

Rebind the contract. `ChainTaxRateSource` tries sources in order and takes the first
that answers:

```php
$this->app->singleton(TaxRateSource::class, fn ($app) => new ChainTaxRateSource([
    new MyCommercialAdapter(...),
    $app->make(RegisterRateSource::class),
]));
```

`CachingTaxRateSource` wraps any source that costs a request per lookup. The register
source needs neither — it reads local files, and there is nothing to expire because the
store holds one pinned release.
