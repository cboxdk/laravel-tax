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

## Scope and dates

`NexusThresholds::for()` accepts an optional date and selects the rule whose
inclusive effective window contains it. Omitting the date means today. The US
regime passes the supply date when producing its advisory annotation. Keep the
release pinned as well when reproducing an earlier result.

The adapter reads `sales_and_transactions`, `sales_or_transactions` and
`sales_only`. Legacy `and`/`or` values remain accepted. A missing operator with a
transaction count, an unknown operator, or overlapping applicable thresholds
raises `UnresolvedTaxRule`; the adapter never invents an OR condition.

Custom implementations must add the optional `?DateTimeImmutable $at = null`
parameter to their `for()` method.

Determining whether a seller crossed a threshold also requires the state's
measuring period and sales basis. That cumulative calculation belongs to
`cboxdk/laravel-nexus` or the host application. This package neither registers the
seller nor infers an obligation from a single invoice.

The amounts and transaction counts can change independently of this package.
Use `tax:data:sync` to install a reviewed release, retain its provenance, and bind
`Contracts\NexusThresholds` if your application supplies its own source.
