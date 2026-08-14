---
title: Flat charges
weight: 4
description: Levy fixed per-supply and per-delivery fees alongside the rate-based tax.
---

# Flat charges

Some levies are not a percentage of anything. Colorado's Retail Delivery Fee is
$0.31; Minnesota's is $0.50. A `TaxRate` is a percentage and refuses to be anything
else — deliberately, because that refusal is load-bearing elsewhere — so a fixed
amount needs its own seam rather than a rate faked from the order total.

**Nothing is shipped.** These levies are per-jurisdiction, move on their own
schedule, and no authoritative compilation of them sits behind this package. The
defaults (`NoFlatCharges`, `NoOrderFlatCharges`) say so plainly, and a host that
knows its own obligations binds a source.

## Two seams, because there are two kinds

| Contract | Levied on | Bound to |
| --- | --- | --- |
| `FlatChargeSource` | one supply | `NoFlatCharges` |
| `OrderFlatChargeSource` | one document, however many lines | `NoOrderFlatCharges` |

The distinction is not cosmetic. A per-*delivery* fee run through the per-supply
seam is charged once per line, so a two-line order pays $0.62 for one delivery. No
care inside a per-supply source can prevent that: it is handed one line at a time
and cannot see that the lines share a delivery.

So within `assessOrder()` the per-supply seam is **not consulted at all** — the
document's own source decides. A standalone `assess()` is a one-line transaction,
where the per-supply seam is exactly right and behaves as it always has.

## A per-delivery fee

```php
use Cbox\Tax\Contracts\OrderFlatChargeSource;
use Cbox\Tax\Enums\{JurisdictionLevel, TaxTreatment};
use Cbox\Tax\ValueObjects\{FlatCharge, OrderAssessment, TaxOrder};

class RetailDeliveryFees implements OrderFlatChargeSource
{
    public function chargesFor(TaxOrder $order, OrderAssessment $assessment): array
    {
        // Due on a delivery that contains taxable goods — which is why the source
        // is handed the finished assessment and not just the order.
        foreach ($assessment->assessments() as $line) {
            if ($line->treatment === TaxTreatment::Standard) {
                return [new FlatCharge(
                    code: 'co_retail_delivery_fee',
                    name: 'Retail Delivery Fee',
                    amount: Money::of('0.31', 'USD'),
                    level: JurisdictionLevel::State,
                )];
            }
        }

        return [];
    }
}
```

Bind it in a service provider:

```php
$this->app->singleton(OrderFlatChargeSource::class, RetailDeliveryFees::class);
```

## Where the money lands

`gross` stays `net + tax` — that invariant holds throughout the engine and several
things depend on it — so charges are added beside it, never folded in:

```php
$assessment->gross();       // the exact sum of the lines' net + tax
$assessment->chargesTotal(); // document-level charges the buyer is billed
$assessment->payable();      // gross + line charges + document charges
```

Set `passedToBuyer: false` for a levy the seller must absorb by statute. It is
still reported — you owe it — but it is excluded from `chargesTotal()` and
`payable()`, because it is not something the buyer owes.
