---
title: Margin scheme and small-business exemption
weight: 16
description: Taxing a second-hand sale on the dealer's margin, and exempting a seller under a member state's turnover threshold.
---

# Margin scheme and small-business exemption

Two things only the seller knows, which is why the engine takes them as input and
never infers them: that a sale is made under the margin scheme, and that the seller
holds the small-business exemption in a member state. The register holds what a state
makes of each.

## Margin scheme

A dealer in second-hand goods, works of art, collectors' items or antiques may pay tax
on its margin instead of on the price (Arts. 311–325 of the VAT Directive; VATA 1994
s. 50A in the United Kingdom). It is an election per supply.

```php
use Cbox\Tax\Enums\MarginGoods;
use Cbox\Tax\ValueObjects\MarginSale;

$assessment = $tax->assess(new TaxQuery(
    amount: Money::of('30.00', 'EUR'),        // the selling price, tax included
    pricing: Pricing::Inclusive,
    place: $geo->find(new CountryCode('DE')),
    customer: CustomerType::Consumer,
    seller: new SellerRegistrations(new CountryCode('NL')),
    margin: new MarginSale(Money::of('10.00', 'EUR'), MarginGoods::SecondHandGoods),
));

$assessment->treatment;        // TaxTreatment::MarginScheme
$assessment->placeOfSupply;    // NL, not DE
$assessment->tax;              // 3.47 = 20 × 21/121
$assessment->margin->margin;   // 20.00
```

`SupplyLine::$margin` takes the same value for a line of a `TaxOrder`.

What the engine does:

- **Where.** The place of dispatch: the ship-from where one is stated, else the seller's
  establishment. The distance-selling rule and the intra-Community exemption do not
  apply (Arts. 35 and 139(3)), so the customer's country, its type and an OSS status
  change nothing.
- **On what.** The margin, tax included: selling price less purchase price, times
  rate / (100 + rate). A sale at a loss owes nothing. A credit note is a negative amount
  and credits what the sale charged.
- **At which rate.** The state's own choice, read from the rule's `rate`: `goods` for
  the rate of the goods, `standard` where the state forces its standard rate (Germany).
- **On export.** The rule's `export`: `exempt` in the Union (Art. 321), `taxed` in the
  United Kingdom from 1 May 2023. Northern Ireland counts as inside the Union for a
  member state's dealer, and a sale from Northern Ireland to a member state as inside
  the United Kingdom's scheme.
- **The invoice.** `TaxTreatment::MarginScheme` is its own treatment because the tax is
  owed and must not be stated: `chargesTax()` is false, `taxWasDue()` true. `mentions`
  carries the wording the state fixes.
- **Delivery.** A delivery line apportioned to a margin line is part of its selling
  price (Art. 312): taxed where it is, all of it margin.

`MarginMethod::Global` is the per-period method. The assessment then has zero tax, no
rate, `RateLimit::MarginPerPeriod` and `margin->taxComputable === false`: the figure
exists on the return, not on the sale.

Stating a `MarginSale` asserts the item was bought without deductible VAT. A host that
wants it on record states the fact `margin.acquiredWithoutDeductibleVat`; stated false,
the election is refused.

Refusals (`UnresolvedTaxRule`):

| `RefusalReason` | When |
| --- | --- |
| `MarginSchemeIneligible` | A service, goods the state excludes by tariff heading, a purchase above the state's cap for the method, the acquisition fact stated false |
| `MarginSchemeUnavailable` | No scheme published for the state, the class or the method; a rule without its `rate` or `export`; goods shipped from a state the seller is neither established nor registered in |

## Small-business exemption

Per seller and per member state (Arts. 284–288a, as amended by Directive (EU)
2020/285, from 1 January 2025):

```php
use Cbox\Tax\ValueObjects\SmallBusinessExemption;

$seller = new SellerRegistrations(
    new CountryCode('NL'),
    smallBusiness: [new SmallBusinessExemption(
        country: new CountryCode('NL'),
        turnover: Money::of('5000.00', 'EUR'),   // this calendar year, before this sale
    )],
);
```

The engine never measures turnover: the host states it, in the threshold's currency,
and the register's `small_business_exemption` rule holds the threshold.

- A supply taxed in a state the seller holds the exemption in is `Exempt`, with the
  state's invoice mention.
- Goods to a business in another member state are exempt at home, not an
  intra-Community supply (Art. 139(1)). A service the customer accounts for stays a
  reverse charge.
- The supply that takes the turnover over the threshold is taxed, measured on the whole
  sale (`TaxQuery::$saleAmount`). Where the state also shuts the seller out for the
  following year, `previousYearTurnover` is read the same way.
- In a state other than its own the seller needs `identificationNumber` (suffix `EX`),
  and `unionTurnoverExceeded` false.

Refused with `SmallBusinessExemptionUnavailable`: a seller established outside the
Union, a state the register publishes no exemption for, another member state without
the EX number.

## Binding your own data

`MarginSchemes` and `SmallBusinessRules` are contracts, bound to the register by default
(`RegisterMarginSchemes`, `RegisterSmallBusiness`). `MarginSchemes::coverage()` and
`SmallBusinessRules::all()` list what the installed release can answer.
