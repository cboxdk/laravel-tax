---
title: Quickstart
weight: 1
description: Assess a supply and read the treatment, tax and reason.
---

# Quickstart

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
