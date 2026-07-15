---
title: Return data
weight: 3
description: Aggregate assessments into per-jurisdiction return totals for filing.
---

# Return data

The `ReturnAggregator` rolls a set of assessments up into return-data — net and
tax totals per jurisdiction and currency — ready for filing. The engine owns the
aggregation; submitting to each authority (e.g. via the UK MTD API or an EU OSS
portal) is the host's concern.

```php
use Cbox\Tax\Contracts\ReturnAggregator;

$return = app(ReturnAggregator::class)->aggregate($assessments);

foreach ($return->lines as $line) {
    $line->country->value;              // "DK"
    $line->currency;                    // "EUR"
    (string) $line->net->getAmount();   // "300.00"
    (string) $line->tax->getAmount();   // "75.00"
    $line->count;                       // number of supplies
}

$return->lineFor(new CountryCode('DK'), 'EUR');
```

Money of different currencies is never mixed — each currency is its own line, so a
jurisdiction billed in more than one currency yields more than one line.
