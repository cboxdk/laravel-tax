---
title: Rate conditions
weight: 8
description: A category finds the neighbourhood; the conditions on a rate decide the house. How a product is described once so every market can settle them.
---

# Rate conditions

A category is not enough to price a product. The United Kingdom zero-rates
agricultural inputs — but only seeds for food crops and live animals of a kind used
for food. Fertiliser files under agricultural inputs and is taxed at 20%. The register
publishes that narrowing as **conditions** on the rate, and the engine reads them.

## Describe the product once, for every market

A seller creating a product does not know every country it will be sold into, and it
should not have to. A product carries three things, none of them country-specific:

| | What | Settles |
| --- | --- | --- |
| **Category** | a `TaxClass` or a register key such as `goods.agricultural_inputs` | which rates are candidates |
| **Commodity code** | the CN or HS code — the one customs needs anyway | every condition written in tariff codes |
| **Facts** | the register's named facts, e.g. `product.isConfectionery` | what a code cannot say |

Store them once in the [product catalogue](../extension-points/product-catalogue.md)
and every market reads the same description:

```php
new ProductTaxMapping(
    TaxClass::GeneralGoods,
    commodityCode: '0102 21 10',                      // live cattle
    categoryKey: 'goods.agricultural_inputs',
);

new ProductTaxMapping(
    TaxClass::GeneralGoods,
    categoryKey: 'goods.food',
    facts: new DecisionFacts(['product.isConfectionery' => true]),
);
```

Or on a single line or query, when the catalogue is not in play:
`new SupplyLine(..., commodityCode: '0102 21 10', facts: new DecisionFacts([...]))`.

## What a condition does to a rate

Each condition is read three ways — true, false or **unknown** — from what the
register typed: a list of tariff codes, a predicate over named facts, or only the
statute's words. A fact nobody supplied is unknown, never false.

| The condition is | A qualifying condition (`applies_only_to`, `supplier_is`, …) | An exclusion (`excludes`) |
| --- | --- | --- |
| **true** | the rate applies | the rate does **not** apply |
| **false** | the rate does **not** apply | the rate applies |
| **unknown** | the rate applies, **flagged** | the rate applies — flagged only if reached from a broader category |

A rate that does not apply is not a candidate at all: the engine carries on to the
answer that does, which is usually the standard rate. So fertiliser with its code
comes back 20%, authoritative; fertiliser with no code comes back 0%, flagged
`RateLimit::ConditionsUnevaluated`, and `OrderAssessment::needsReview()` catches it.

**Why exclusions are treated differently at the exact category.** Ireland zero-rates
books "but excluding newspapers". Asked about a book, that exclusion is about
something else — flagging it would put a caveat on a large share of all answers to
warn about a few. Asked about sweets, which only reach the UK's food zero rate by
climbing to it, the same kind of exclusion is the whole question, and it is flagged.

## A code settles both ways only when the condition is written in codes

A commodity code answers any condition published as a tariff list, in both
directions: bovine animals are in `0102`, fertiliser is not. Where the register
publishes a condition as **either** a code list **or** a product fact, a code can only
confirm it — a fertiliser code proves the code limb false, but the fact limb stays
open, and "either" cannot be called false while one side is unknown. Those answers
stay flagged until the fact is given too.

## Finding what is missing, before the first invoice

`CatalogueAudit` reads the catalogue against the markets a seller is opening, and
reports only where the description falls short:

```php
$findings = app(CatalogueAudit::class)->audit(
    $productSkus,
    [$geo->find(new CountryCode('GB')), $geo->find(new CountryCode('DE'))],
);

foreach ($findings as $finding) {
    $finding->itemCode;
    $finding->market;                      // the jurisdiction, or null if unmapped
    $finding->settleableByCommodityCode(); // would adding the CN/HS code settle it?
    $finding->factsNeeded();               // the register facts that would settle the rest
    $finding->conditions;                  // each with the statute's own words
}
```

A product that is fully described does not appear. Nothing here blocks a sale — an
open condition is still priced at the published rate, flagged — this is how the flag
is found once per market rather than on every checkout.

## What can never be settled

A condition published only as the statute's words has no codes and no facts to
supply. It stays flagged, and `UnsettledCondition::isSettleable()` says so: read the
words and decide, or put your own source in front with `ChainTaxRateSource`.

The register's data is in beta — see [beta status](../coverage/data-status.md).
