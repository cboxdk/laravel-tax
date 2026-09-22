---
title: A webshop checkout
weight: 1
description: Price a whole basket — several lines plus shipping — for one selling entity, and store what an invoice needs to be explained later.
---

# A webshop checkout

A basket is a document, not a pile of supplies: the lines share a buyer, a seller and
a date, the shipping is apportioned across them, and the totals have to reconcile to
what the customer is charged. `TaxOrder` is that document.

```php
use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Enums\{CustomerType, Pricing, TaxClass};
use Cbox\Tax\ValueObjects\{SellerRegistrations, SupplyLine, TaxOrder};

$geo = app(JurisdictionRepository::class);

$order = new TaxOrder(
    place: $geo->find(new CountryCode('DE')),        // where the buyer is
    customer: CustomerType::Consumer,
    seller: $tenant->taxRegistrations(),             // this shop's own entity
    pricing: Pricing::Inclusive,                     // shelf prices include VAT
    lines: [
        new SupplyLine('sku-101', Money::of('49.90', 'EUR'), TaxClass::GeneralGoods, quantity: 2),
        new SupplyLine('sku-204', Money::of('19.00', 'EUR'), categoryKey: 'goods.publications.book'),
        new SupplyLine('shipping', Money::of('4.95', 'EUR'), isDeliveryCharge: true),
    ],
    suppliedAt: $cart->placedAt,      // the tax point: when the supply was made
);

$assessment = app(OrderTaxCalculator::class)->assessOrder($order);
```

Three things in there are worth pointing at.

**`quantity` is how many items the amount covers.** `amount` is always the extended
amount — unit price × quantity, less any discount — and the quantity exists so that
per-item caps work. Massachusetts exempts clothing up to $175 *per article*, so two
$150 coats on one line are two exempt coats, not one $300 taxable supply.

**`categoryKey` asks the register in its own words.** `TaxClass` covers the common
cases; the register publishes 182 categories, and a key reaches the ones the enum
cannot name — `goods.publications.book`, `services.education`,
`goods.medical_equipment.prosthetic`. An unknown key refuses and names the nearest
published ones rather than quietly pricing as general goods.

**Shipping is a line, flagged as one.** It is assessed after the goods, because what
freight is taxed at depends on what it delivered. Where the goods carry several
rates, the freight is split between them by value.

## Reading the result

```php
$assessment->net();                   // Money — the sum of the lines
$assessment->tax();                   // summed from the rounded lines, never recomputed
$assessment->gross();
$assessment->forLine('sku-204');      // that line's own assessment, with its own rate
$assessment->taxByAuthority();        // per-jurisdiction totals for a remittance, or null
```

## Before the order goes out

```php
if ($assessment->needsReview()) {
    foreach ($assessment->limits() as $limit) {
        report($limit->value.' — '.$limit->remedy());
    }
}
```

`needsReview()` is true when anything on the document is less than authoritative: a
rate resolved from a broader category, a US service taxed because nothing published
says otherwise, an address that only reached the state line, a marketplace mandate
whose conditions were not evaluated. Every limit carries `remedy()` — the one step
that would close it — and `callerCanClose()` says whether that step is yours.

Blocking the checkout on it is a policy decision. Blocking on it is defensible for a
first sale into a new country; for a long tail of flagged-but-fine lines, recording
the flag on the order and reviewing the list weekly usually is not worse.

## What to store on the order

Store enough to explain the number a year from now, when both the rates and this
package have moved on:

| Store | From | Why |
| --- | --- | --- |
| treatment, net, tax, gross | the assessment | what you charged |
| the reason string | `$line->reason` | the sentence for the accountant |
| release version | `$line->rate?->provenance?->version` | which published data answered |
| effective date, section hash | `$line->rate?->provenance?->effectiveFrom`, `?->sectionHash` | from when the rate applied, and the exact bytes it was read from |
| confidence and `limitedBy` | `$line->rate` | whether it was exact, and what was missing |
| the seller registrations used | your tenant record | the other half of the calculation |

With the release version stored you can reproduce any historical invoice exactly:
install that release, pin it, and re-price. See
[backfilling old invoices](backfilling-invoices.md).
