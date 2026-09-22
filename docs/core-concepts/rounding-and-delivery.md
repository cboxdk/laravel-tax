---
title: Rounding and delivery
weight: 9
description: Published rounding policies, invoice reconciliation and conditional delivery charges.
---

# Rounding and delivery

Rounding methods and delivery exclusions are sourced rules. The engine owns the
arithmetic, decisions and reconciliation; the host supplies transaction facts.

## Rounding policies

The US regime obtains a dated `TaxRounding` through `Contracts\RoundingRules`.
The default `RegisterRounding` reads `rounding` records from the installed release.
Supported methods are `half_up` and `up`, with explicit decimal places, combined
local shares and line/invoice scope. Unsupported policies or gaps within published
rounding history raise `UnresolvedTaxRule`.

`seller_election` permits the host to choose `RoundingScope::Line` (the default)
or `RoundingScope::Invoice` using `TaxQuery::$roundingScope` or
`TaxOrder::$roundingScope`. A fixed published scope takes precedence over that choice.
An absent local-aggregation assertion is accepted for a state-only rate; it cannot
authorize aggregation of supplied local components.

An invoice policy keeps exact tax until lines and delivery portions have been
grouped by rate. Each group is rounded once, and the resulting minor units are
distributed deterministically. Exclusive lines keep their net amounts; inclusive
lines keep their gross amounts. Credits reverse rounding away from zero. Authority
breakdowns are allocated from each line's actual tax and reconcile to it.
`TaxAssessment::$taxableBase` retains a partial taxable net base even when the
provisional line tax is zero and invoice reconciliation later allocates a cent.

The default maths where no policy is published remains half-up. Calling
`TaxRate::taxOnNet()` directly is a pure arithmetic operation: supply its optional
`TaxRounding` argument to apply a policy, or use the calculator to resolve one.
The engine does not silently round again when a money context cannot represent
the policy's precision.

## Delivery facts

Flag a delivery line and, where an exclusion applies, confirm its conditions:

```php
use Brick\Money\Money;
use Cbox\Tax\ValueObjects\DeliveryCharge;
use Cbox\Tax\ValueObjects\SupplyLine;

$delivery = new SupplyLine(
    id: 'delivery',
    amount: Money::of('10.00', 'USD'),
    isDeliveryCharge: true,
    delivery: new DeliveryCharge(exclusionConditionsMet: true),
);
```

`exclusionConditionsMet` is a host assertion that **all applicable published
conditions have been checked**. It is not an unconditional exemption switch:

- With a published exclusion, `true` applies it; `false` assesses taxable delivery
  of taxable goods; `null` refuses because the facts are unknown.
- With a published inclusion, the host assertion cannot override the rule.
- No applicable US delivery record means a refusal, including dates before a
  capture floor. A host can bind `Contracts\DeliveryRules` for coverage it maintains.
- Taxable delivery of exempt goods requires an independent classification/rate
  that the current delivery model cannot resolve, and refuses explicitly.
- Taxable delivery of partially exempt goods needs an allocation rule for its taxable
  base and also refuses until that rule is modelled.

The component defaults to `DeliveryComponent::Transport`.
`DeliveryComponent::Handling` reads the separate handling rule. The order supplies
the delivered goods' taxability from their assessments; a standalone delivery
`TaxQuery` must supply `DeliveryCharge::$goodsTaxable` itself.

A US delivery rule is only reached where the seller holds that state's permit (or the
sale was marketplace-facilitated). Without one the supply is `NotRegistered` and there
is no charge to exclude freight from — see
[seller registrations](seller-registrations.md).

Goods are assessed first, then the delivery is allocated using the order's
`ApportionmentBasis`. US delivery uses the component rule and the goods' outcome;
the freight price is not treated as the price of another product for a per-item
exemption. EU delivery continues to follow the supplied products' rates.
Delivery portions are retained on `TaxAssessment::$portions`, including their
dates and rounding policy. Document charges are applied once at order level.

## Upgrade notes

Custom `MarketplaceRules` implementations answer `liability(): MarketplaceLiability`
in place of `platformOwes(): bool`; return `MarketplaceLiability::PlatformOwes` for
what used to be `true` and `SellerCollects` for `false`. Custom `DeliveryRules`
implement `treatment()` in place of `included()`.

Custom `SourcingRules` and `NexusThresholds` implementations must accept the new
optional `?DateTimeImmutable $at = null` argument. Existing one-argument calls
still work and mean today. The US regime passes the supply date to both.

Identified mixed intrastate sourcing now refuses until a regime can select a place
per authority layer. Missing delivery facts use `RefusalReason::DeliveryFactsRequired`;
unsupported or conflicting rules use `RefusalReason::TaxRuleUnsupported`.
Both implement the package's `Refusal` contract for a structured 422 response.
