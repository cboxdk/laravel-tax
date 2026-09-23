---
title: Product catalogue
weight: 15
description: Map your SKUs to tax classes once, send the item code per line, and get told which products nobody has classified yet.
---

# Product catalogue

A tax class is a fact about a **product**, not a decision to make while building an
invoice. Decide it on the line and you decide it again on every invoice, in whatever
code path happens to be writing that line, with no record and nothing to review.
Ten thousand SKUs become ten thousand chances to pick differently.

So: map once, send the code.

```php
// A provider in your app.
$this->app->singleton(ProductCatalogue::class, fn () => new ArrayProductCatalogue([
    'SHOE-001' => TaxClass::Footwear,
    'MILK-1L'  => new ProductTaxMapping(TaxClass::Groceries, 'cn:04011010'),
]));

// Then every line just carries your own code.
new TaxQuery(/* … */, itemCode: 'SHOE-001');
```

`ArrayProductCatalogue` suits a fixed catalogue — a SaaS with nine plans, a shop
with fifty SKUs. Anything larger implements the contract against your own product
table.

## What a mapping carries

A mapping is the product's whole tax description, and none of it depends on where the
product is sold:

```php
new ProductTaxMapping(
    TaxClass::GeneralGoods,
    commodityCode: '0102 21 10',                 // CN or HS — customs needs it anyway
    categoryKey: 'goods.agricultural_inputs',    // the register's key, where the class cannot say it
    facts: new DecisionFacts([                   // what a code cannot say
        'product.isLiveAnimalOfAKindYieldingHumanFood' => true,
    ]),
);
```

The commodity code is the one worth filling in. It settles every
[condition](../core-concepts/rate-conditions.md) the register writes in tariff terms,
in every market, without anyone knowing which markets those are. Facts are for the
rest. A query's own facts win over the catalogue's, so a sale can still say something
the product description cannot.

## Opening a market

`CatalogueAudit` reads the catalogue against a list of markets and reports which
products need more description there — and whether the commodity code or a named fact
would supply it. Run it when a seller opens a market, not on every checkout. See
[rate conditions](../core-concepts/rate-conditions.md#finding-what-is-missing-before-the-first-invoice).

## Resolution is three-deep

| | Wins over | When to use it |
| --- | --- | --- |
| A `category` on the query | everything | This line is classified differently from the product in general |
| The catalogue, by `itemCode` | the fallback | Normal operation |
| The shipped fallback | — | A code nothing has mapped |

The third is where engines quietly go wrong, and it is why this is a contract rather
than an array lookup. An unmapped SKU still has to produce an invoice, so it gets
the fallback — and then nothing says it did. The line is taxed at the standard rate,
which is right for most products and wrong for exactly the ones a reduced rate
exists for.

**So an unmapped code is reported.** The assessment's rate carries
`RateLimit::ItemUnmapped`, and a review can list every SKU nobody has classified:

```php
$assessment->rate?->limitedBy;              // RateLimit::ItemUnmapped
$assessment->rate?->limitedBy?->remedy();   // what to do about it
```

## Finding the class

`TaxClass::search()` matches the words you already use for the product, against the
merchant-facing name and concrete examples rather than the enum's own value —
somebody selling trainers types "trainers", not "footwear".

```php
TaxClass::search('trainers');   // [Footwear]
TaxClass::search('laptops');    // [Electronics]
TaxClass::search('notebooks');  // []
```

**An empty result is the important answer.** Nothing here expresses school supplies.
Learning that at mapping time, where you can record it, beats learning it from a
holiday weekend where they were exempt and you charged anyway.

Each class carries what a picker needs to render a row — `info()->name`,
`->examples`, `->cnPrefixes`, and the Annex III point that permits a reduced rate at
all.

## The loop this is built for

1. **Map coarsely.** Search, pick a class per SKU, ship. Most products need nothing
   more, because the class alone is right for most supplies in most countries.
2. **Let the engine tell you what is not enough.** Lines come back carrying a
   `RateLimit` naming the gap and the one step that closes it. Sort by
   `callerCanClose()`: those are yours to fix by classifying, the rest are the
   operator's to fix by configuration.
3. **Refine only what it flagged.** A `HeadingAmbiguous` line wants a commodity code
   on the product — Hungary rates foodstuffs at 5% and 18% at once, and
   `cn:04011010` settles it. Add it to the mapping, not to the invoice.

That is the whole point of reporting rather than silently defaulting: the work is
finite and the engine tells you which part of it matters.
