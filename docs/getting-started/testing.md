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

Pass no rates to use the helper's fixed test fixture; those figures are not a
production rate source. Jurisdictions come from the real
`laravel-geo` repository, so place-of-supply behaviour is exercised for free.

## A register for the application container

```php
use Cbox\Tax\Testing\FakeRegister;

$store = sys_get_temp_dir().'/my-tax-test';
FakeRegister::at($store)->rate('eu:DK', '25')->install();
config()->set('tax.register.store', $store);
config()->set('tax.register.version', null);
```

Set the configuration before resolving the calculator or register from the
container. Use a separate temporary directory per test and remove it afterwards.
`FakeRegister` supplies invented test data and does not need network access.

The package's full `composer qa` gate includes the live `e2e` group. For an offline
iteration, run `vendor/bin/pest --exclude-group=e2e`; that is a narrower check than
the full gate.

## Independent result checks

`composer test:reference` syncs the release pinned in
`conformance/reference/2026-09-17.json`, verifies its local hashes and assesses
41 dated reference cases through the application container. Pricing runs with
outbound HTTP blocked. Expected rates and rules come from public tax-authority
sources; monetary expectations are sourced examples or independently derived
arithmetic. The register does not generate them.

The cases cover standard, reduced and zero rates, local US rate components,
inclusive pricing, credit notes, reverse charge, a rate-change boundary and a
mixed-rate order with delivery. To compare a newer register release against the
same dated expectations:

```bash
CBOX_TAX_REFERENCE_RELEASE=latest composer test:reference
```

These checks use supplied US localities and do not independently validate street
addresses or geocoding. The corpus records the reference URLs, review dates,
inputs and assumptions for reviewing those limits.

## Exemptions

The same trait builds buyer [exemptions](../core-concepts/exemptions.md) from ISO
code strings and asserts exempt outcomes:

```php
$assessment = $calc->assess(new TaxQuery(
    // …amount, pricing, place, customer, seller…
    exemption: $this->taxExemption(
        type: ExemptionType::Resale,
        reference: 'CA-RESALE-42',
        subdivisions: ['US-CA'],   // or countries: ['DK'] for national VAT
    ),
));

$this->assertExempt($assessment, 'CA-RESALE-42'); // Exempt, tax 0, gross = net, reference present
```

## Testing a product form

`FakeRegister::fact()` publishes a fact in the release's vocabulary, so a product form
driven by `CatalogueAudit::productQuestions()` can be tested without the network:

```php
FakeRegister::at(config('tax.register.store'))
    ->fact('product.isConfectionery', 'Is this confectionery, such as sweets, candy, chocolate or chewing gum?')
    ->fact('recipient.isCharityServingDisabledPersons', 'Is the buyer a charity providing care for disabled people?', subject: 'recipient')
    ->install();
```

## Testing address resolution by point

`FakeRegister::geometry()` publishes a state's polygon layer, so a point lookup can be
tested without the network. Features name the register's own codes; format 3 adds
`replaces` for a combined area that stands in place of others:

```php
FakeRegister::at(config('tax.register.store'))
    ->rate('us:TX', '6.25')
    ->rate('us:TX:CITY-2227001', '1', 'local_component')
    ->geometry('TX', [[
        'type' => 'Feature',
        'properties' => ['authority' => 'us:TX:CITY-2227001', 'level' => 'city'],
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-97.8, 30.2], [-97.7, 30.2], [-97.7, 30.3], [-97.8, 30.3], [-97.8, 30.2]]]],
    ]])
    ->install();
```
