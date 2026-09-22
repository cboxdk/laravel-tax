---
title: Marketplace facilitator
weight: 45
description: When a marketplace is liable to collect, the seller charges nothing — and the sale is still reported. Pass marketplaceFacilitated and the engine checks the law on the supply's date.
---

# Marketplace facilitator

Every US state with a sales tax makes a qualifying marketplace the party liable to
collect on its third-party sellers' supplies. Missouri closed the set on
**1 January 2023**. Where that applies, **the seller charges nothing**. A seller who
charges anyway double-charges the customer on every marketplace order.

**The EU is not that.** Art. 14a deems an electronic interface the supplier for two
specific limbs — a distance sale of goods imported in a consignment worth at most
EUR 150, or goods already in the Community sold by a seller established outside it
to a customer who is not a taxable person — and the register now publishes those
conditions typed rather than as prose. The engine does not yet evaluate them, so it
does the one safe thing: the **seller keeps charging**, and the rate is stamped
`RateLimit::MarketplaceLiabilityUnread`. Reading the mandate as if it covered every
facilitated sale would hand the tax to a platform the Directive does not reach, and
then nobody collects it.

```php
new TaxQuery(
    // …
    marketplaceFacilitated: true,
);
```

Document-level too — the facilitator relationship is a fact about the transaction,
not about one product on it:

```php
new TaxOrder(
    // …
    marketplaceFacilitated: true,
);
```

## It is not "exempt", and the difference lands on a return

Four treatments produce a zero charge and they mean opposite things:

| Treatment | What it says |
| --- | --- |
| `Exempt` | No tax was due |
| `NotRegistered` | This seller had no obligation in that state |
| `ZeroRated` | A real 0% rate applied |
| **`MarketplaceFacilitated`** | **Tax was due, and somebody else remitted it** |

A fifth outcome charges rather than zeroing: `Standard` with
`RateLimit::MarketplaceLiabilityUnread` on the rate, which says the place deems a
platform liable for *some* facilitated sales and this reader has not settled whether
this is one of them. `OrderAssessment::needsReview()` is true for it, so a checkout
that blocks on anything unsettled already blocks on this.

Most states still expect the seller to report the sale in gross receipts and then
deduct it as marketplace-facilitated. A treatment that collapsed these would file a
wrong return while charging the right amount, so `taxWasDue()` is what a filing
asks rather than `chargesTax()`.

## The contract

`MarketplaceRules::liability(CountryCode $country, DateTimeImmutable $on)` answers
with a `MarketplaceLiability`: `PlatformOwes`, `SellerCollects`, or `Conditioned` for
a mandate that names conditions. It replaced a boolean `platformOwes()`, which could
not tell the third case from the second — and reported it as the first.

The default binding reads the register's `marketplace_facilitator` rules for every
jurisdiction outside the United States, whose states are read by the US regime's own
facts. Bind your own implementation to decide it yourself.

## Only you know it happened; only the data knows if it applies

Whether a given platform qualifies as a facilitator, and whether it has taken on
collection for this supply, is a fact about a commercial arrangement. Nothing in a
rate table can answer it, so the engine takes your assertion.

It then checks the **law**, on the supply's date. A Missouri sale from 2022
predates the rule and is still the seller's to collect — answering from today's map
would zero a charge that was really owed.

**Two states carry no date and therefore never apply it.** Arizona's published
figure predates its marketplace act by three years and looks like an earlier
remote-seller provision; Alaska has no state sales tax to hang one on. Both leave
the tax with the seller, which is the recoverable direction: charging twice is
visible to the customer and refundable, charging nothing surfaces in an audit years
later.

## What still decides

**Taxability.** A marketplace collects nothing on an exempt supply, so an exempt
line is reported `Exempt` for its own reason rather than credited to the
marketplace.

**Not your nexus.** The check runs *before* the seller's own registration, because
the marketplace's liability is not derived from the seller's presence. A seller with
no nexus in the state still owes nothing on a facilitated sale — and the reason says
why, where `NotRegistered` would have said something else entirely on the same zero.
