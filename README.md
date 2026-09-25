# Cbox Tax

**Consumption tax for Laravel.** Send it a sale — who is selling, who is buying, what
it is — and get back a number you can defend: the treatment, the rate, the authorities
it splits across, and the published source it came from.

No tax SaaS in the request path. Nothing is fetched while pricing.

```php
$assessment = app(TaxCalculator::class)->assess($query);   // German seller → French business

$assessment->tax;                  // Money 0.00 EUR — the seller charges nothing
$assessment->treatment;            // TaxTreatment::ReverseCharge — the customer accounts for it
$assessment->mentions[0]->text;    // "Exempt intra-Community supply" — wording the invoice needs
$assessment->reason;               // the whole sentence, with the article it rests on
```

Zero, but not "no tax was due": the tax is the French customer's to account for, the
invoice has to say so, and the return has to report it. Six treatments keep those
distinctions apart — see [what comes back](#what-comes-back).

## From install to a priced sale, in three steps

**1 — Install**

```bash
composer require cboxdk/laravel-tax
```

**2 — Install the data** (once, and on every deploy — like `php artisan migrate`)

```bash
php artisan tax:data:sync
```

This compiles a published release of [the register](https://data.cboxtax.com) onto
local disk: 11 tax regimes, every rate carrying the source and the date it was
captured. Until it has run, the engine refuses to price anything rather than guess.

**3 — Ask**

```php
use Brick\Money\Money;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\{CustomerType, Pricing};
use Cbox\Tax\ValueObjects\{SellerRegistrations, TaxQuery};

$assessment = app(TaxCalculator::class)->assess(new TaxQuery(
    amount: Money::of('100.00', 'EUR'),
    pricing: Pricing::Exclusive,
    place: $geo->find(new CountryCode('FR')),      // the buyer, from laravel-geo
    customer: CustomerType::Business,
    seller: new SellerRegistrations(new CountryCode('DE')),
    customerTaxIdValidated: true,                  // VIES-validated
));
```

A German company invoicing a validated French business is an intra-EU B2B supply, so
nothing is charged and the buyer self-accounts. Change the seller to a French entity
and the same query charges French VAT. That is the whole idea: **tax is a function of
the facts you send**, and every fact has somewhere to go.

→ [Quickstart](docs/quickstart.md) · [Upgrading from 0.9](docs/getting-started/upgrading.md)

## What comes back

`TaxAssessment` is built to survive an audit, not just to add a line to a cart.

| | |
| --- | --- |
| `treatment` | `Standard`, `ReverseCharge`, `IntraCommunitySupply`, `ZeroRated`, `Exempt`, `NotRegistered`, `MarketplaceFacilitated` — six different zeros that mean different things on a return |
| `tax`, `net`, `gross` | exact `brick/money`, rounded by the jurisdiction's own published policy |
| `rate->provenance` | which release, which dataset, effective from when, and a hash of the section it was read from |
| `rate->confidence`, `rate->limitedBy` | whether this is exact, and if not, the one step that would close the gap |
| `breakdown` | the state / county / city / district shares, allocated from the tax actually charged so they sum to it exactly |
| `reason` | a sentence a human can read out to an accountant |

## It refuses rather than guess

Three outcomes that most engines collapse into "0", kept apart on purpose:

- **No tax applies** — a resolved, authoritative zero.
- **Zero-rated or exempt** — a real published band, and the treatment says which: they go in different boxes on a return.
- **Not determinable** — an exception, not a number. A jurisdiction with no regime, a
  missing rate, a threshold the register says it has not modelled: all refuse.

Between the two extremes sits the honest middle: an answer that is *probably* right
and says why it might not be. A rate resolved from a broader category, a US service
taxed because nothing published says otherwise, an address that only resolved to the
state line — each comes back flagged, with a remedy attached.
`OrderAssessment::needsReview()` is the one call that tells you whether anything on a
document needs a human.

## Where the logic ends and the data begins

```
your app ──► TaxQuery ──► regime  (logic this package owns:
                          │        place of supply, reverse charge,
                          │        registration, exemptions, rounding)
                          ▼
                      the register  (data cboxtax publishes:
                          │          rates, rules, boundaries, provenance)
                          ▼
                    TaxAssessment
```

The engine never invents a rate, and the register never decides a treatment. Both
sides are contracts: bind your own `TaxRateSource`, `ProductTaxability`,
`MarketplaceRules` or `DeliveryRules` and the engine keeps working.

## Coverage

| | |
| --- | --- |
| **EU VAT** | Place of supply per the Directive — goods and electronic services at the customer, general B2C services at the supplier — intra-EU B2B as an exempt Art. 138 supply, the Art. 59c €10,000 relief, and the ten special territories a country code cannot find |
| **National VAT/GST** | UK, CH, NO, AU, NZ, MX, SG, TW, AE, SA, BH, OM, TR, CL, ID, VN, PH, JP, KR, TH, UA |
| **India · Malaysia** | Dual GST (IGST vs CGST+SGST) · SST service tax |
| **United States** | Nexus, taxability, marketplace and intrastate-sourcing gates, with local authorities stacked to the house number where a state publishes a street index, the point in California and New Mexico, and ZIP+4 across the Streamlined states |
| **Canada** | Federal GST plus the province's PST/QST, or a harmonised HST in its place |

25 regime modules across 52 countries. The register carries data for more
jurisdictions than the engine models rules for — a rate existing does not make a
country supported, and the difference is
[written down](docs/coverage/not-yet-supported.md).

## Built for platforms, not just one shop

- **Every selling entity is separate.** `SellerRegistrations` travels with the query:
  establishment, foreign numbers, US state permits, Canadian province permits, OSS or
  IOSS, each with its own validity window. Nothing lives in global config, so a
  multi-tenant host prices each tenant as itself.
  → [Seller registrations](docs/core-concepts/seller-registrations.md)
- **Invoices, not just supplies.** `TaxOrder` assesses a whole document: per-line
  verdicts, delivery apportioned across the lines it delivers, mixed-rate freight
  split per authority, and `taxByAuthority()` for the remittance.
- **Marketplaces.** Say a sale was facilitated and the engine checks whether that
  place's law actually moves the liability — and says so when the law only moves it
  for *some* facilitated sales, instead of quietly handing the tax to a platform.
- **Backfills are correct.** Every dated lookup — rate, taxability, exemption,
  registration — resolves on the supply's tax point, so recalculating last year does
  not apply this year's world to it.

## Data you can pin

The register is compiled, content-addressed and swapped atomically:

```bash
php artisan tax:data:sync --release=2026.09.22-261   # pin a release
php artisan tax:data:verify                          # sha256 per file, offline
php artisan tax:data:activate previous               # instant rollback
php artisan tax:data:status                          # what is live, what is installed
```

The package is MIT. **The register's data is licensed separately** (PolyForm Internal
Use 1.0.0): computing your own tax is covered; redistributing the data or reselling
lookups from it is not. → [The register](docs/getting-started/the-register.md)

## The data is in beta

Rates are read from primary sources and carry their provenance, and the engine
refuses rather than guessing where nothing published answers. **That is not the same
as being right**, and there will be errors nobody has found yet — in the data, and in
the law this engine models.

So: act on `needsReview()`, check the countries you actually sell in against your own
adviser's numbers, and store the release version with each invoice so a later
correction can be traced to the orders it touched. The package is MIT and the
register's data is licensed separately; both are provided as-is, and neither is tax
advice. A wrong number is worth reporting —
[open an issue](https://github.com/cboxdk/laravel-tax/issues) with the release
version and it gets fixed in the data or in the code.

→ [Beta status and responsibility](docs/coverage/data-status.md)

## Documentation

| | |
| --- | --- |
| [Quickstart](docs/quickstart.md) | Zero to a priced supply in one read |
| [Cookbook](docs/cookbook/_index.md) | A checkout, a marketplace sale, a backfill |
| [Core concepts](docs/core-concepts/_index.md) | Regimes, seller registrations, dates, exemptions, rounding, breakdowns |
| [Coverage](docs/coverage/_index.md) | What is modelled, what the register publishes, and what is neither |
| [Beta status](docs/coverage/data-status.md) | What is guaranteed, what is not, and what stays yours |
| [Extension points](docs/extension-points/_index.md) | Bind your own sources, geocoder, catalogue, resolvers |
| [Upgrading from 0.9](docs/getting-started/upgrading.md) | The retired data sources, changed contracts, and the numbers that move |

## Requirements

PHP `^8.4` (tested on 8.4 and 8.5) with `ext-dom` and `ext-zlib`, Laravel `^13`, and
[`cboxdk/laravel-geo`](https://github.com/cboxdk/laravel-geo) for jurisdictions. See
[requirements](docs/requirements.md).

## Development

```bash
composer install
composer qa          # pint, phpstan (level max), pest, licence check, audit
composer test:reference   # the independent conformance corpus
```
