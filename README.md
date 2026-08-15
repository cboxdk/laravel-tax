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
supplies the rate number — the EU Commission's TEDB called live (no API key), the
compiled US dataset, or a commercial adapter. A missing rate is **refused, never
assumed 0%**.

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
| **EU VAT** | `eu-vat` — Art. 44/45/58 place-of-supply (general B2C services source at the supplier; goods and electronic services at the customer), intra-EU B2B reverse charge, Art. 59c €10k micro-business relief scoped to the supplies it covers; rates live from the Commission's TEDB | ✅ |
| **National VAT/GST** | UK, CH, NO, AU, NZ, MX, SG, TW, UAE, SA, BH, OM, TR, CL, ID, VN, PH, JP, KR, TH, UA | ✅ |
| **India** | `in-gst` — dual GST (IGST vs CGST+SGST), OIDAR destination, B2B reverse charge | ✅ |
| **Malaysia** | `my-sst` — SST service tax; charges B2B+B2C, no reverse charge | ✅ |
| **US sales tax** | `us-sales-tax` — nexus, taxability and intrastate-sourcing gates, with rates, 25-category taxability, nexus thresholds and sourcing rules from the **us-tax-data dataset** (all 51 jurisdictions, on by default) | ✅ address-exact for 30 states |
| **Canada GST/HST** | `ca-gst` — province-level combined rate, cross-border B2B self-assessment | ✅ |

See [`docs/coverage`](docs/coverage/_index.md) for the full per-country table with
sources and confidence — and an honest list of jurisdictions we **omit** until
their rate data is verified (a broad national-VAT batch pending primary-source
confirmation, Pakistan's other provinces, and Brazil). We omit rather than ship a
rate we cannot stand behind.

The **US** regime gates on three things before applying a rate — the state must be
resolved (via the `AddressGeocoder`), the seller must have **nexus** in it, and the
product must be **taxable** there — otherwise it returns `NotRegistered` or
`Exempt`, never a wrong charge. A category the dataset leaves undetermined, or one
whose rule is conditional on the line amount (the MA/NY/RI clothing thresholds),
**refuses** rather than defaulting to taxable — over-collecting from a consumer is
a failure too. State rates, per-state taxability (25 categories) and economic-nexus
thresholds are supplied by the **us-tax-data dataset**, enabled by default.
**Intrastate sourcing is applied**, not just supplied: nine states tax an in-state
sale at the seller's location, so give the supply a `SupplyRoute(shipFrom: …)` and
a Texas in-state sale is charged the seller's rate. Interstate stays
destination-sourced everywhere, and a supply with no route behaves exactly as
before. **Address-exact** rates are live for **30 states**. Twenty-six need
`us_tax_data.rooftop` enabled: the 24 Streamlined states resolve by ZIP+4 through the
published boundary index — Kansas City comes out as 6.5% state + 1.0% county + 1.625%
city — while California and New Mexico resolve by point against their own polygon
services. Florida, Pennsylvania, Hawaii and Virginia need no opt-in and no boundary file at
all, because the county is the only authority that can tax there and a geocoder
returns it for free. The rest fall back to the state rate
([details](docs/coverage/us-tax-dataset.md#rooftop-zip4-into-the-boundary-index)).
**Marketplace sales are not the seller's to collect.** Every US state with a sales
tax now makes a qualifying marketplace the liable party — Missouri closed the set on
2023-01-01 — so pass `marketplaceFacilitated: true` and the engine returns
`MarketplaceFacilitated`: nothing charged, because the marketplace already charged
it. It is kept apart from `Exempt` and `NotRegistered` on purpose. All three are a
zero and they mean opposite things on a return, and most states still expect the
sale reported in gross receipts and then deducted. The rule is checked **on the
supply's date**, so a backdated Missouri sale from 2022 is still the seller's.

**Canada** resolves at province level (no local tax). Rate data plugs in via
`TaxRateSource`: set `TAX_TEDB_LIVE=true` to resolve EU rates from the
Commission's own TEDB service (no key, no registration, cached per country), or
bind a commercial adapter — see [`docs/coverage`](docs/coverage/_index.md).

**EU** place of supply follows the Directive rather than a single rule: goods
(Art. 33(a)) and electronically-supplied services (Art. 58) are taxed at the
customer, while a general B2C service is taxed **where the supplier is
established** (Art. 45) — so a German consultancy invoicing a French consumer owes
German VAT. On top of that sits the **Art. 59c €10,000 micro-business threshold**,
scoped to the supplies it actually covers (goods and TBE, not services generally):
a below-threshold, non-opted seller charges origin VAT; opted-in or over-threshold
charges destination. Rate sources resolve by **taxability category**, so
reduced/zero bands apply when a bound source supplies them (none are fabricated by
default).

Unmodelled jurisdictions and missing rates are **refused, not guessed**.

## Documents, not just single supplies

A real invoice is multi-line. `TaxOrder` carries the context every line shares plus
`SupplyLine[]`, and `OrderTaxCalculator::assessOrder()` returns each line's verdict
tied to the id you sent:

```php
$assessment = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
    place: $geo->find(new CountryCode('DK')),
    customer: CustomerType::Consumer,
    seller: new SellerRegistrations(new CountryCode('DK')),
    pricing: Pricing::Exclusive,
    lines: [
        new SupplyLine('subscription', Money::of('100.00', 'DKK'), TaxCategory::DigitalService),
        new SupplyLine('usage',        Money::of('37.50',  'DKK'), TaxCategory::DigitalService),
        new SupplyLine('onboarding',   Money::of('2500.00','DKK'), TaxCategory::ServicesProfessional),
    ],
));

$assessment->tax();              // summed from the rounded lines, never recomputed
$assessment->forLine('usage');   // that line's own assessment
$assessment->taxByAuthority();   // per-jurisdiction totals for remittance, or null
```

The order plane adds **no** tax logic — each line becomes a single-supply query and
runs the identical path, so a document cannot reach an outcome a single supply
could not. A line may override the document's pricing (VAT-inclusive subscription
beside exclusive usage) or carry its own exemption.

## Rate breakdown

Where a rate is **stacked** from several authorities — a US state share plus the
county, city and special-district records a rooftop lookup matched — the
assessment carries a `TaxBreakdown` splitting the tax across them, so a seller can
remit per jurisdiction. The shares are **allocated from the tax actually charged**,
never recomputed per authority, so they sum to it exactly and a return reconciles
with the invoices behind it. A `null` breakdown means the split is **unknown**, not
that one authority takes everything. See
[`docs/core-concepts/rate-breakdown.md`](docs/core-concepts/rate-breakdown.md).

## Buyer exemptions

A query may carry a native buyer **exemption** (a resale / nonprofit / government
certificate) on `TaxQuery::$exemption`. Applied deny-by-default over the regime's
verdict, a valid exemption that covers the taxed jurisdiction rewrites a would-be
`Standard` line to `Exempt` (net kept, tax 0, gross = net) with the certificate
reference recorded on the assessment; reverse-charge, not-registered and zero-rated
outcomes are left untouched, and an exemption for a different jurisdiction or an
expired one does not exempt. The engine computes the assessment; **certificate
capture and verification are the consumer's concern.** See
[`docs/core-concepts/exemptions.md`](docs/core-concepts/exemptions.md).

## Design

- **Contracts-first.** `TaxCalculator`, `TaxRegime`, `TaxRateSource`,
  `RegimeRegistry`, `AddressGeocoder`, `VatIdValidator`, `ReturnAggregator` — bind
  and override any of them. Rate sources compose (static · remote · caching · chain).
- **Deny-by-default.** No regime for a jurisdiction, or no rate, → an exception,
  never a silent zero.
- **Money is exact.** Amounts are `brick/money`; rate maths rounds half-up once.

## Requirements

PHP `^8.4` with `ext-dom`; Laravel `^13`. See `composer.json`.

## Development

```bash
composer install
composer qa    # pint --test, phpstan (level max), pest, license-check, audit
```

## License

MIT.
