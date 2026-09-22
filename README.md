# Cbox Tax

**`cboxdk/laravel-tax`** — a self-hostable consumption-tax engine for Laravel. It
**owns the calculation logic** — place-of-supply, reverse-charge, rate application,
inclusive/exclusive — and **reads rate and rule data** through pluggable
contracts. No forced third-party calculation SaaS.

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

The engine decides *whether and how* to tax; the rate number comes from
**[the register](https://data.cboxtax.com)** — 80 jurisdictions across eleven regimes,
compiled to local disk by `php artisan tax:data:sync` and read without a network call.
A missing rate is **refused, never assumed 0%**, and that includes the first run: until
the register is synced the engine refuses and says so.

The [data/engine validation matrix](conformance/validation-matrix.md) distinguishes
published facts, engine logic, adapters and host inputs. It also records reference
data still held in PHP and what the current tests establish for each layer.

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
| **EU VAT** | `eu-vat` — Art. 44/45/58 place-of-supply (general B2C services source at the supplier; goods and electronic services at the customer), intra-EU B2B reverse charge, Art. 59c €10k micro-business relief scoped to the supplies it covers; rates from the register, which reads the Commission's TEDB among its sources | ✅ |
| **National VAT/GST** | UK, CH, NO, AU, NZ, MX, SG, TW, UAE, SA, BH, OM, TR, CL, ID, VN, PH, JP, KR, TH, UA | ✅ |
| **India** | `in-gst` — dual GST (IGST vs CGST+SGST), OIDAR destination, B2B reverse charge | ✅ |
| **Malaysia** | `my-sst` — SST service tax; charges B2B+B2C, no reverse charge | ✅ |
| **US sales tax** | `us-sales-tax` — nexus, taxability and intrastate-sourcing gates, with rates, category taxability, nexus thresholds and sourcing rules from **the register** (all 51 jurisdictions) | ✅ address-exact for 30 states |
| **Canada GST/HST** | `ca-gst` — province-level combined rate, cross-border B2B self-assessment | ✅ |

See [`docs/coverage`](docs/coverage/_index.md) for supported regimes and limitations. The default geo profiles and regime registry model **52
countries**; the register's broader data coverage does not automatically add their
calculation rules to this engine. Unsupported regimes refuse before rate lookup.

The **US** regime gates on three things before applying a rate — the state must be
resolved (via the `AddressGeocoder`), the seller must have **nexus** in it, and the
product must be **taxable** there — otherwise it returns `NotRegistered` or
`Exempt`. Price exemptions use the line amount and the published threshold rule;
incomplete threshold records refuse. A category with no published determination in a known
jurisdiction defaults to taxable — a behaviour change from the retired dataset's
explicit undetermined verdicts. State rates, category taxability and economic-nexus
thresholds come from **the register**.
**Intrastate sourcing is applied**, not just supplied: nine states tax an in-state
sale at the seller's location, so give the supply a `SupplyRoute(shipFrom: …)` and
a Texas in-state sale is charged the seller's rate. Interstate stays
destination-sourced everywhere, and a supply with no route behaves exactly as
before. **Address-exact** rates are live for 30 states. The 24 Streamlined states resolve by
ZIP+4 through the published boundary index — Kansas City comes out as 6.5% state + 1.0%
county + 1.625% city — fifteen of them go finer still with a street index
(`tax:data:sync --streets=KS`), and California and New Mexico resolve by point against their own
polygon layers. Florida, Pennsylvania, Hawaii and Virginia need no boundary file at all,
because the county is the only authority that can tax there and a geocoder returns it
for free. The rest fall back to the state share, flagged
([details](docs/coverage/the-register.md)).
**Remote-seller elections close two of those states on request.** Alabama's SSUT
(flat 8%) and Texas' Single Local Use Tax Rate (6.25% + 1.75% for 2026) are
statutory schemes a remote seller elects into; give the state registration the
`remote-election` scheme ([how](docs/core-concepts/seller-registrations.md#schemes))
and the engine prices under them — opt-in, dated, and
refusing rather than guessing when the published figure lapses.
**Marketplace sales are not the seller's to collect.** Every US state with a sales
tax now makes a qualifying marketplace the liable party — Missouri closed the set on
2023-01-01 — so pass `marketplaceFacilitated: true` and the engine returns
`MarketplaceFacilitated`: nothing charged, because the marketplace already charged
it. It is kept apart from `Exempt` and `NotRegistered` on purpose. All three are a
zero and they mean opposite things on a return, and most states still expect the
sale reported in gross receipts and then deducted. The rule is checked **on the
supply's date**, so a backdated Missouri sale from 2022 is still the seller's.

**Outside the US, collection is gated on registration.** A supply into a country
where the seller is neither established nor registered is `NotRegistered`, not a
charge — the tax is due at the border instead. An OSS or IOSS registration covers the
whole Union. See [seller registrations](docs/core-concepts/seller-registrations.md).

**Canada** resolves at province level (no local tax), as the federal GST plus the
province's share: an HST replaces the federal rate, a PST is added to it, and a PST is
collected only by a seller holding that province's own permit. Every regime reads the same
register; to put your own source in front of it, bind `TaxRateSource` — see
[`docs/coverage`](docs/coverage/_index.md).

**EU** place of supply follows the Directive rather than a single rule: goods
(Art. 33(a)) and electronically-supplied services (Art. 58) are taxed at the
customer, while a general B2C service is taxed **where the supplier is
established** (Art. 45) — so a German consultancy invoicing a French consumer owes
German VAT. On top of that sits the **Art. 59c €10,000 micro-business threshold**,
scoped to the supplies it actually covers (goods and TBE, not services generally):
a below-threshold, non-opted seller charges origin VAT; opted-in or over-threshold
charges destination. Rate sources resolve by **taxability category**, so
reduced/zero bands apply where the installed register supplies an applicable rate.

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
        new SupplyLine('subscription', Money::of('100.00', 'DKK'), TaxClass::DigitalService),
        new SupplyLine('usage',        Money::of('37.50',  'DKK'), TaxClass::DigitalService),
        new SupplyLine('onboarding',   Money::of('2500.00','DKK'), TaxClass::ProfessionalService),
    ],
));

$assessment->tax();              // summed from the rounded lines, never recomputed
$assessment->forLine('usage');   // that line's own assessment
$assessment->taxByAuthority();   // per-jurisdiction totals for remittance, or null
```

Each order line uses the same regime and input facts as a single supply. Delivery
is assessed after the goods, and published invoice rounding reconciles the rate
groups before totals are returned. A line may override the document's pricing or
carry its own exemption. See [rounding and delivery](docs/core-concepts/rounding-and-delivery.md)
for rounding elections, conditional delivery facts and supported rule shapes.

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
- **Money is exact.** Amounts are `brick/money`; published rounding policies control
  method, precision and line/invoice scope, with half-up where no policy is published.

## Requirements

PHP `^8.4` with `ext-dom` and `ext-zlib`; Laravel `^13`. See `composer.json`.

## Development

```bash
composer install
composer qa    # pint --test, phpstan (level max), pest (including live e2e), license-check, audit
vendor/bin/pest --exclude-group=e2e  # fixture tests when working offline
```

## License

MIT.
