---
title: A marketplace sale
weight: 2
description: Assert that a platform facilitated the sale, and read what the law of that place actually does with the liability.
---

# A marketplace sale

Two parties know different halves of this. **You** know a platform facilitated the
sale and took on collection — nothing in a rate table can know that. **The register**
knows whether the law of the place of supply moves the liability because of it.

```php
$assessment = app(TaxCalculator::class)->assess(new TaxQuery(
    amount: Money::of('100.00', 'GBP'),
    pricing: Pricing::Exclusive,
    place: $geo->find(new CountryCode('GB')),
    customer: CustomerType::Consumer,
    seller: $seller->taxRegistrations(),
    suppliedAt: $sale->completedAt,
    marketplaceFacilitated: true,          // your assertion about the arrangement
));
```

Three things can come back, and they are not variations of "zero":

| Treatment | What happened |
| --- | --- |
| `MarketplaceFacilitated` | The law makes the platform liable. The seller charges nothing, and most places still expect the sale reported and then deducted. |
| `Standard` | No deeming rule reaches this place, so the assertion changed nothing and the seller's own obligation stands. |
| `Standard`, with `RateLimit::MarketplaceLiabilityUnread` on the rate | A rule exists and reaches only *some* facilitated sales. The seller keeps charging, and the flag says the question is open. |

The third is the EU today. Article 14a deems an electronic interface the supplier for
two specific limbs — a distance sale of imported goods in a consignment worth at most
EUR 150, or goods already in the Community sold by a seller established outside it to
a customer who is not a taxable person — and the register publishes those conditions
typed. This engine does not evaluate them yet, so it does the recoverable thing: the
seller charges, and the assessment says the liability was not settled. Reading the
rule as a blanket mandate would hand the tax to a platform the Directive does not
reach, and then nobody collects it.

```php
$assessment->rate?->limitedBy === RateLimit::MarketplaceLiabilityUnread;
```

## Deciding it yourself

If you know your own platform's position — you are the interface, and you have taken
legal advice on which of your sales Article 14a reaches — bind `MarketplaceRules` and
answer directly:

```php
use Cbox\Tax\Contracts\MarketplaceRules;
use Cbox\Tax\Enums\MarketplaceLiability;

$this->app->bind(MarketplaceRules::class, fn () => new class implements MarketplaceRules
{
    public function liability(CountryCode $country, DateTimeImmutable $on): MarketplaceLiability
    {
        return $this->deemedSupplierIn($country, $on)
            ? MarketplaceLiability::PlatformOwes
            : MarketplaceLiability::SellerCollects;
    }
});
```

The engine takes your answer for the place and the date; everything else — the rate,
the treatment, the reporting — carries on as before.

## The date matters

The check runs on the **supply's** date, not today's. A Missouri sale from 2022
predates that state's marketplace act and is still the seller's to collect; answering
from today's map would zero a charge that was really owed. Pass `suppliedAt` on
anything you reprice.

## It runs before nexus

The platform's liability is not derived from the seller's presence, so the check runs
*before* the seller's own registration. A seller with no nexus in the state still owes
nothing on a facilitated sale — and the treatment says why, where `NotRegistered`
would have said something else entirely about the same zero.

See [marketplace facilitator](../core-concepts/marketplace-facilitator.md) for the
full outcome table.
