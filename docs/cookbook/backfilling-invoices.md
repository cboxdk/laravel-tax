---
title: Backfilling old invoices
weight: 3
description: Re-price last year without applying this year's rates, registrations or rules to it — and reproduce a historical number exactly.
---

# Backfilling old invoices

Recalculating history is the first thing a new integration does, and the easiest
place to produce a confident wrong number. Three separate clocks have to be set back,
not one.

## 1. The tax point

Pass `suppliedAt` on every query. It is threaded through **every** dated lookup for
that supply — the rate, the product's taxability, the buyer's exemption, the seller's
registrations, and every dated rule. An assessment priced with one year's rate and
another year's taxability is internally inconsistent in a way that still looks like a
number.

```php
new TaxQuery(
    // …
    suppliedAt: $invoice->supplied_at,      // the tax point
    reportedOn: $invoice->invoiced_at,      // the period it belongs to, if they differ
);
```

Supplied on 30 December, invoiced on 3 January: December's rate, January's return. One
date cannot do both jobs, which is why there are two.

## 2. The seller's registrations, as they were then

A registration has a lifetime, and backfilling is exactly the case it exists for. A
seller registered in Texas on 1 March did not owe Texas tax in February; without the
window, today's registrations are applied to last year's supplies and the return
covers a period the seller was not registered in.

```php
new SellerRegistration(
    new CountryCode('US'),
    new SubdivisionCode('US-TX'),
    validFrom: new DateTimeImmutable('2026-03-01'),
    validUntil: null,                        // still in force
);
```

Store registrations with their dates in the first place, and a backfill needs no
special code path. See
[seller registrations](../core-concepts/seller-registrations.md).

## 3. The release the number came from

Rates move, and so does the register's reading of them. To reproduce a specific
historical invoice, price it against the release that produced it:

```bash
php artisan tax:data:sync --release=2026.09.22-261
TAX_REGISTER_VERSION=2026.09.22-261 php artisan invoices:reprice
```

A pinned release that is not installed refuses rather than quietly using the active
one, and pruning never removes a pinned or active release. This is why
[the checkout recipe](webshop-checkout.md) stores
`$assessment->rate?->provenance?->version` on the order: with it, any invoice can be
re-derived years later; without it, you can only re-derive *a* number.

Historical rates also live inside the current release — each carries its own effective
window — so a recent backfill usually needs only the tax point. Pin the release when
you need the answer to be byte-for-byte the one you gave the customer, or when the
register has since corrected a rate and you want the old reading, not the new one.

## Comparing two engines

Migrating from another calculation source means both will disagree somewhere, and the
useful question is which disagreements are explained. Price the same invoices both
ways and sort the differences by `limitedBy`:

```php
$rows = $invoices->map(fn ($i) => [
    'id' => $i->id,
    'old' => $i->tax_amount,
    'new' => ($a = $reprice($i))->tax->getAmount(),
    'why' => $a->rate?->limitedBy?->value ?? $a->treatment->value,
]);
```

Most differences land in a handful of buckets — a registration you have not stated, a
category the register reads more narrowly, a place whose collection rules changed —
and each bucket is one decision rather than a thousand rows. Anything left over is
worth reporting; the
[data/engine validation matrix](https://github.com/cboxdk/laravel-tax/blob/main/conformance/validation-matrix.md)
records which layer owns which kind of answer.
