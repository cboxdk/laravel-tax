---
title: Local authorities
weight: 25
description: Bind a LocalAuthorityResolver to resolve US addresses below the state line where the installed register cannot — a state portal you hold credentials for, a commercial adapter, or your own boundary file.
---

# Local authorities

Some states have local tax but no shipped address-resolution path. A state-only
answer then carries `Confidence::Derived` and `NoLocalResolution`; see
[register coverage](../coverage/the-register.md).

`LocalAuthorityResolver` is where you close that for the states you care about.
Bind one, and the US rate source stacks whatever it returns.

```php
// A provider in your app.
$this->app->singleton(LocalAuthorityResolver::class, fn () => new ColoradoGisResolver(
    apiKey: config('services.colorado_suts.key'),
));
```

## Why this is a contract and not something we ship

Several states run a free address-level lookup of their own, and Colorado's carries
something no data feed can: under **CRS 39-26-105.2**, a vendor who relies on the
Department's GIS database is **held harmless** in an audit for local tax that came
out wrong because the database was — provided they can produce documentation of
having relied on it.

That protection attaches to *the vendor who used it*. It cannot be obtained on your
behalf by routing your lookups through somebody else's credentials. You register for
[SUTS](https://tax.colorado.gov/SUTS-info), you hold your own API key, and you earn
your own protection. Shipping an implementation with our key would quietly strip the
one thing that made the source worth using.

The same seam serves any better resolution you have — a commercial adapter, an
internal boundary file, a state portal.

## Three answers, and the last two are not the same

```php
public function authoritiesFor(Jurisdiction $jurisdiction, ?DateTimeImmutable $at = null): ?array;
```

| Return | Means | The engine |
| --- | --- | --- |
| `null` | "I do not answer for this address" | Uses the state share, flagged where local tax may be missing |
| `[]` | "No local authority taxes here" | Prices at the state share and calls it **`Authoritative`** — that IS the whole rate |
| `['us:KS', 'us:KS:COUNTY-209', 'us:KS:CITY-36000']` | The authorities that apply | Sums the complete set, including its state member, with a `RateComponent` each |

**A failed lookup returns `null`, never `[]`.** An unreachable service is not
knowledge that no tax applies. Returning `[]` there would publish a confident
under-charge.

**List every authority that applies.** The engine sums them; a short list is an
under-charge stamped `Authoritative`, which is the outcome this package works
hardest to prevent. Codes are the register's full jurisdiction codes, including
the state code when its share applies — see the `rates` section of [the register](../coverage/the-register.md). A code
the register does not carry makes the whole stack refuse and fall back to the state
rate, rather than silently dropping that authority's share.

**`$at` is the supply date, not today.** Addresses change hands between districts,
so a backdated credit note needs the authorities that applied then. If your source
cannot answer historically, return `null` for a past date rather than today's answer.

## It is asked first, and without a locality

The bound resolver replaces the default `RegisterBoundaries` and is asked about the whole
jurisdiction rather than about a locality. That matters: a Colorado address carries
no locality at all, because nothing shipped resolves Colorado below the state line —
which is exactly the case a resolver exists to cover.

Where both could answer, yours wins. Binding one is a deliberate act.

## Testing

`FakeLocalAuthorityResolver` scripts answers per jurisdiction and records every call:

```php
$fake = new FakeLocalAuthorityResolver;
$fake->resolve($kansasCity, ['us:KS', 'us:KS:COUNTY-209', 'us:KS:CITY-36000']);

$this->app->instance(LocalAuthorityResolver::class, $fake);

// …assess…

expect($fake->wasConsultedFor($kansasCity))->toBeTrue();
```

`wasConsultedFor()` earns its place: the mistake worth catching is a resolver that
is bound but never reached. The assessment still comes out with a plausible number —
the state share — and nothing in the result says the lookup never happened.

Anything unscripted defers to the state-share fallback; it does not assert an
empty authority set.

## What is not solved here

Binding a resolver does not make this package a certified provider in any state, and
the hold-harmless above is Colorado's own provision attaching to your use of
Colorado's database — not a warranty from us. Read the statute before relying on it.
