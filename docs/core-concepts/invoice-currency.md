---
title: Invoice currency
weight: 11
description: Stating the tax in the place's own currency when the invoice is in another, at the rate in force at the tax point.
---

# Invoice currency

An invoice may be in any currency, and its tax is still owed in the currency of the
place that levies it. A Danish sale invoiced in euros owes Danish kroner, and Art. 91
of the VAT Directive says how to convert: at the rate in force when the tax became
chargeable, which a member state may set as the European Central Bank's.

## What an assessment carries

When the amounts are in another currency than the place of supply's, the assessment
carries the rate for its tax point, and converts on demand:

```php
$assessment->tax;                       // EUR 25.00
$assessment->exchangeRate;              // EUR → DKK 7.463, ECB, 2026-09-25
$assessment->taxInLocalCurrency();      // DKK 186.58

$order->taxInLocalCurrency();           // the document's tax, converted once from the total
```

The order's figure is converted **once, from the total**. Each line converted and
rounded on its own can drift from it: three lines of 0.01 EUR are 0.07 DKK each, 0.21
together, where the order's 0.03 EUR is 0.22 DKK. Print the document's figure on the
invoice.

Where amounts and place share a currency, `exchangeRate` is null and nothing is
converted. Where no rate is known for the date, it is null too: nothing is refused
and nothing is guessed, and the conversion is yours.

## Where the rate comes from

The shipped `ExchangeRates` reads the ECB's euro reference rates from local disk:

```bash
php artisan tax:fx:sync     # schedule it daily
```

Nothing is fetched while pricing. The bank publishes one rate per currency per
business day, as units per euro, and a rate between two other currencies is read
through the euro. On a weekend or a holiday the latest earlier rate is in force; one
older than a week is not used, because that is a store nobody synced rather than a
holiday.

**Your own source.** Some countries take their own bank's rate, and a customs
authority publishes monthly ones. Bind `Cbox\Tax\Contracts\ExchangeRates` to your
implementation and every assessment uses it.
