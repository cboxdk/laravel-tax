---
title: Quickstart
weight: 1
description: Assess a supply and read the treatment, tax and reason.
---

# Quickstart

## 0. Install the data

No rates ship inside the package, so the first step is installing a register release.
Until it has run, the engine refuses to price anything rather than guess:

```bash
composer require cboxdk/laravel-tax
php artisan tax:data:sync
```

That compiles a published release onto local disk. Put it in your deploy next to
`php artisan migrate`, and see [the register](getting-started/the-register.md) for
pinning a release, verifying it and rolling back.

## 1. Price a supply

```php
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\ValueObjects\{TaxQuery, SellerRegistrations};
use Cbox\Tax\Enums\{CustomerType, Pricing};
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Brick\Money\Money;

$geo = app(JurisdictionRepository::class);
$tax = app(TaxCalculator::class);

$assessment = $tax->assess(new TaxQuery(
    amount: Money::of('100.00', 'EUR'),
    pricing: Pricing::Exclusive,
    place: $geo->find(new CountryCode('DK')),
    customer: CustomerType::Consumer,
    seller: new SellerRegistrations(new CountryCode('DK')),
));

$assessment->treatment;              // TaxTreatment::Standard
(string) $assessment->tax->getAmount();   // "25.00"
(string) $assessment->gross->getAmount(); // "125.00"
$assessment->reason;                 // "EU VAT: domestic tax at 25% in DK."
```

## 2. Read what came back

`treatment` is the answer to "was tax due, and whose is it?" — six of them, and five
produce a zero for different reasons. `reason` is the sentence for whoever asks later.
Two more fields decide whether a human should look:

```php
$assessment->rate?->confidence;   // Confidence::Authoritative — exact for what was asked
$assessment->rate?->limitedBy;    // null, or the one gap in this answer
$assessment->rate?->provenance;   // which release and dataset, effective from when
```

A flagged answer is still a number you can charge; it carries `remedy()`, the single
step that would make it exact. A question the engine cannot answer at all throws
instead — `UnresolvedTaxRate`, `UnsupportedJurisdiction`, `DatasetNotInstalled` —
because a plausible wrong rate is worse than an error you can see.

## 3. Change one fact, get a different answer

Cross-border intra-EU B2B to a validated customer reverse-charges instead:

```php
$tax->assess(new TaxQuery(
    amount: Money::of('100.00', 'EUR'),
    pricing: Pricing::Exclusive,
    place: $geo->find(new CountryCode('FR')),
    customer: CustomerType::Business,
    seller: new SellerRegistrations(new CountryCode('DE')),
    customerTaxIdValidated: true,
))->treatment; // TaxTreatment::ReverseCharge
```

The seller is half the calculation: swap the German entity for a French one and the
same query charges French VAT. Everything the selling entity can state — foreign
registrations, US state permits, a province's PST permit, OSS or IOSS, each with its
own validity window — is on
[`SellerRegistrations`](core-concepts/seller-registrations.md).

## Next

- [A webshop checkout](cookbook/webshop-checkout.md) — a whole basket, with shipping
  and per-line verdicts.
- [Regimes](core-concepts/regimes.md) — what each one models, and the collection gate
  that runs after it.
- [Testing](getting-started/testing.md) — build a register in three lines with
  `FakeRegister`; no network, no fixtures to maintain.
