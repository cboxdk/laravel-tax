---
title: Testing
weight: 2
description: Build a calculator with chosen rates using the dogfooded testing trait.
---

# Testing

`Testing\InteractsWithTax` builds a calculator with the shipped regimes and a rate
map you choose — the package's own suite uses it:

```php
use Cbox\Tax\Testing\InteractsWithTax;

$calc = $this->taxCalculator(['DK' => '25', 'FR' => '20']);

$assessment = $calc->assess($query);
```

Pass no rates to use the built-in defaults. Jurisdictions come from the real
`laravel-geo` repository, so place-of-supply behaviour is exercised for free.
