# Cbox Tax

**`cboxdk/laravel-tax`** — a self-hostable consumption-tax engine for Laravel. It
**owns the calculation logic** — place-of-supply, reverse-charge, rate application,
inclusive/exclusive — and **sources only the rate data** behind a pluggable
contract. No forced third-party calculation SaaS.

> Built on [`cboxdk/laravel-geo`](https://github.com/cboxdk/laravel-geo): every
> supply is assessed against a jurisdiction resolved from canonical ISO data, so
> tax is a function of `(seller registrations, buyer jurisdiction, product type)`
> — never a fuzzy country-name match.

## The boundary: own the logic, source the data

```php
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\Enums\{CustomerType, Pricing};
use Cbox\Geo\ValueObjects\CountryCode;
use Brick\Money\Money;

$assessment = app(TaxCalculator::class)->assess(new TaxQuery(
    amount: Money::of('100.00', 'EUR'),
    pricing: Pricing::Exclusive,
    place: $geo->find(new CountryCode('FR')),   // buyer jurisdiction (from laravel-geo)
    customer: CustomerType::Business,
    seller: new SellerRegistrations(new CountryCode('DE')),
    customerTaxIdValidated: true,               // VIES-validated
));

$assessment->treatment;   // TaxTreatment::ReverseCharge — intra-EU B2B, buyer self-accounts
$assessment->tax;         // Money 0.00 EUR
$assessment->reason;      // human-readable explanation for the audit trail
```

The engine decides *whether and how* to tax; the **`TaxRateSource`** contract
supplies the rate number (an EU TEDB feed, the SST files, a commercial adapter).
A missing rate is **refused, never assumed 0%**.

## Multi-entity / seller-of-record routing

Tax depends on *which selling entity* issues the invoice. The same buyer is taxed
differently by a German entity vs a French one:

| Selling entity | Buyer (FR business, validated) | Result |
| --- | --- | --- |
| German entity | cross-border intra-EU B2B | **reverse charge** — no VAT charged |
| French entity | domestic supply | **French VAT** is charged |

`SellerRegistrations` (establishment + registrations) is the seller side of the
calculation the billing engine supplies per invoice.

## What's covered

| | Regime | Status |
| --- | --- | --- |
| **EU VAT** | `eu-vat` — Art. 44/45/58 place-of-supply, intra-EU B2B reverse charge | ✅ |
| **National VAT/GST** | UK, CH, NO, AU, NZ, MX, Singapore, Taiwan, UAE, Saudi Arabia, Bahrain, Oman, Türkiye, Chile, Indonesia, Vietnam, Philippines | ✅ |
| **India** | `in-gst` — dual GST (IGST vs CGST+SGST), OIDAR destination, B2B reverse charge | ✅ |
| **US sales tax** | `us-sales-tax` — nexus gate, per-state taxability, state/rooftop rate | ✅ |
| **Canada GST/HST** | `ca-gst` — province-level combined rate, cross-border B2B self-assessment | ✅ |

See [`docs/coverage`](docs/coverage/_index.md) for the full per-country table with
sources and confidence — and an honest list of jurisdictions we **omit** until
their rate data is verified (a broad national-VAT batch pending primary-source
confirmation, Pakistan's other provinces, and Brazil). We omit rather than ship a
rate we cannot stand behind.

The **US** regime gates on three things before applying a rate — the state must be
resolved (rooftop via the `AddressGeocoder`), the seller must have **nexus** in it,
and the product must be **taxable** there — otherwise it returns `NotRegistered` or
`Exempt`, never a wrong charge. **Canada** resolves at province level (no local
tax). The shipped **`GeocodioGeocoder`** (`AddressGeocoder`) resolves US/Canada
addresses; rate data (TEDB, SST, commercial) plugs in via `TaxRateSource`.

Unmodelled jurisdictions and missing rates are **refused, not guessed**.

## Design

- **Contracts-first.** `TaxCalculator`, `TaxRegime`, `TaxRateSource`,
  `RegimeRegistry`, `AddressGeocoder` — bind and override any of them.
- **Deny-by-default.** No regime for a jurisdiction, or no rate, → an exception,
  never a silent zero.
- **Money is exact.** Amounts are `brick/money`; rate maths rounds half-up once.

## Requirements

PHP `^8.4`; Laravel `^12 || ^13`. See `composer.json`.

## Development

```bash
composer install
composer qa    # pint --test, phpstan (level max), pest, license-check, audit
```

## License

MIT.
