---
title: US economic-nexus thresholds
weight: 5
description: How the register supplies remote-seller thresholds and how the engine uses them.
---

# US economic-nexus thresholds

The default `NexusThresholds` binding is `RegisterNexus`, which reads `threshold`
rules from the [installed register](../getting-started/the-register.md). It selects
USD thresholds whose `binds` field is `remote_seller`. The old static threshold
table has been removed.

## What the engine does with them

The US regime takes nexus from an explicit `SellerRegistration`. When the seller
is not registered in a state, the `NotRegistered` assessment reason includes the
available threshold as an indication that registration may be needed.

```php
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\NexusThresholds;

$threshold = app(NexusThresholds::class)->for(
    new SubdivisionCode('US-TX'),
    new DateTimeImmutable('2026-09-18'),
);
$description = $threshold?->describe();
```

A missing threshold returns `null`; it does not establish that a seller has no
obligation. The lookup supplies figures and a combinator, not a decision about a
seller's accumulated sales.

## What counts, and from when — `measuredBy` and `obligations`

Twelve states publish more than a number. `NexusThreshold` carries both extra parts
in the state's own words, because applying them needs facts only the host has:

```php
foreach ($threshold->measuredBy as $rule) {
    // marketplace_sales / excluded, affiliated_persons / aggregate, transaction_unit / invoice …
    [$rule->dimension, $rule->treatment, $rule->says];
}

foreach ($threshold->obligations as $obligation) {
    // remit / first_crossing_in_current_year / first_month_start_on_or_after_days / 30
    [$obligation->action, $obligation->trigger, $obligation->dateKind, $obligation->dateFigure];
}
```

**`measuredBy`** changes whether the figure was crossed at all: Arizona excludes
marketplace-facilitated sales from the count and aggregates affiliated persons, so a
seller measuring gross turnover against the bare figure can be wrong in either
direction. **`obligations`** changes the date collection starts — Arizona's is the
first day of the month beginning at least 30 days after the crossing, not the
crossing itself, so a system that starts charging immediately bills tax for up to two
months the state did not ask for.

Both are reported, never applied. This package is not told the seller's marketplace
sales, its affiliates, or the day it crossed. Refusing them instead left Arizona,
California, Colorado, Iowa, Michigan, Minnesota, North Carolina, North Dakota,
Oklahoma, Tennessee, Vermont and Wisconsin with no threshold at all, which was worse.

## Scope and dates

`NexusThresholds::for()` accepts an optional date and selects the rule whose
inclusive effective window contains it. Omitting the date means today. The US
regime passes the supply date when producing its advisory annotation. Keep the
release pinned as well when reproducing an earlier result.

The adapter reads the combinators `sales_and_transactions`, `sales_or_transactions`
and `sales_only`; legacy `and`/`or` values remain accepted. A **missing or unknown
combinator where a transaction count is published** raises `UnresolvedTaxRule` —
the adapter never invents an OR condition — as do overlapping applicable thresholds
and a threshold whose applicability is itself conditional (`conditions`).

The **operators** are a different field: `amountOperator` and `transactionsOperator`
say whether the figure is crossed by exceeding it or by reaching it ("more than
$500,000" against "$500,000 or more"). Most states publish none, so an absent
operator is permitted and reported as `null`; only a stated value this reader does
not know refuses. A threshold qualified by `unresolvedQualifications` — the register
saying it has not modelled the statutory trigger — also refuses.

Custom implementations must add the optional `?DateTimeImmutable $at = null`
parameter to their `for()` method.

Determining whether a seller crossed a threshold also requires the state's
measuring period and sales basis, plus the `measuredBy` rules above. That cumulative
calculation belongs to `cboxdk/laravel-nexus` or the host application, which then
states the outcome to this package as a
[seller registration](../core-concepts/seller-registrations.md) in the state. This package neither registers the
seller nor infers an obligation from a single invoice.

The amounts and transaction counts can change independently of this package.
Use `tax:data:sync` to install a reviewed release, retain its provenance, and bind
`Contracts\NexusThresholds` if your application supplies its own source.
