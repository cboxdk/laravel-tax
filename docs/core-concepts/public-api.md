---
title: Public API
weight: 10
description: What the version number promises — the contracts, value objects, enums, exceptions and testing helpers — and what is internal.
---

# Public API

The version number is a promise about some of the package, not all of it. From 1.0,
a breaking change to anything on this page waits for the next major; anything not on
it may change in a minor release.

## What is covered

- **Contracts** (`Cbox\Tax\Contracts\*`) — every interface: their methods, parameters
  and return types. Bind your own implementation of any of them, or decorate the
  shipped one; that is what they are for.
- **Value objects** (`Cbox\Tax\ValueObjects\*`) — their public properties and
  methods, and their constructors.
- **Enums** (`Cbox\Tax\Enums\*`) — their cases and methods. A new case can arrive in a
  minor release where the register gains a fact the engine did not model; a `match`
  over an enum you do not own should keep a `default` arm.
- **Exceptions** (`Cbox\Tax\Exceptions\*`) — the classes and the `Refusal` interface a
  caller catches on.
- **Testing** (`Cbox\Tax\Testing\*`) — the fakes and the `InteractsWith*` traits.
- **Configuration and commands** — the keys in `config/tax.php` and the `tax:data:*`
  commands with their options.

## What is not

- **The register's reader, store and compiler** (`Cbox\Tax\Register\Reader`,
  `Register\Store`, `Register\Compile`) are internal and marked `@internal`. They
  change with the register's format. Ask the register through `TaxRegister`
  instead — the release in use, its categories, what a US state needs, the EU
  distance-sales threshold, the rate records it files.
- **Constructors of the shipped implementations** — `RegisterRateSource`,
  `GeocodioGeocoder`, the regimes and the rest. Resolve them from the container, where
  the service provider builds them; their methods are covered through the contracts
  they implement, their constructors are not. None of them is sealed, so extending one
  to change a method is supported — calling its constructor yourself ties you to its
  dependencies.
- **Anything marked `@internal`.**

## Asking the register

```php
use Cbox\Tax\Contracts\TaxRegister;

$register = app(TaxRegister::class);

$register->release()?->version;              // "2026.09.25-310"
$register->assertCategoryPublished($key);    // UnknownCategory, naming the nearest keys
$register->localResolution('TX');            // LocalResolution::Address
$register->distanceSalesThreshold()?->amount; // EUR 10000.00
$register->rateRecords($place, '2026.09.01-1'); // what an older installed release filed
```
