---
title: Upgrading from 0.9
weight: 4
description: What changed between 0.9 and 0.14 for an application already in production — the data plane, the contracts, and the numbers that move on their own.
---

# Upgrading from 0.9

One thing dominates this upgrade: **the data plane was replaced**. Everything the
package used to fetch at runtime — the EU Commission's TEDB service, the
`ibericode/vat-rates` feed, the `us-tax-dataset` repository, the ArcGIS polygon
services and the static snapshot shipped in `resources/rates.json` — is gone, and
every rate now comes from one published register compiled to local disk.

Read [the register](the-register.md) first; this page is only the delta.

## 1. Install the register before the first assessment

There is no bundled fallback any more. An empty store refuses with
`DatasetNotInstalled`, naming the command rather than pricing anything, so
`tax:data:sync` belongs in your deploy next to `php artisan migrate`:

```bash
php artisan tax:data:sync
```

The first sync downloads ~6.5 MB and writes ~63 MB. Nothing is fetched while
pricing — a lookup reads one record from a local shard.

**Nothing is scheduled for you.** The register publishes several times a day and
which version you price against is a decision somebody makes, so the cadence is
yours: sync on deploy, and use `--check` from cron to find out whether you are
behind without downloading anything (it exits non-zero when you are).

For deterministic deploys, pin the release and verify it:

```bash
php artisan tax:data:sync --release=2026.09.22-261   # or TAX_REGISTER_VERSION
php artisan tax:data:verify                          # offline sha256 + size, per file
php artisan tax:data:activate previous               # one rename; instant rollback
php artisan tax:data:status --offline                # what is live, what is installed
```

A pinned release that is not installed **refuses** rather than quietly pricing from
the active one. Pruning (`tax:data:prune`, `tax.register.keep`, default 2) never
removes the active or the pinned release.

Compile only what you sell into: `--region=eu`, `--state=TX`, or
`tax.register.regions` / `tax.register.states`. The US is 38 MB of rates on its own
and all ten non-US regimes together are 4 MB. Anything outside what you compiled
refuses rather than guessing.

## 2. Config keys

Remove `tax.us_tax_data`, `tax.eu_vat` and `tax.tedb` — with them go
`TAX_US_DATASET*`, `TAX_EU_VAT_*` and `TAX_TEDB_*`. The one setting that survived
moved: rooftop resolution is now `tax.geocodio.rooftop` (`GEOCODIO_ROOFTOP`, still
reading `TAX_US_DATASET_ROOFTOP` as a fallback), because it is a geocoder capability
rather than a dataset one. Republish the config file or add the new block:

```php
'register' => [
    'url'        => env('TAX_REGISTER_URL', 'https://data.cboxtax.com'),
    'store'      => env('TAX_REGISTER_STORE'),      // null → storage_path('app/cbox-tax/register')
    'version'    => env('TAX_REGISTER_VERSION'),    // pins pricing AND sync; null follows `current`
    'regions'    => env('TAX_REGISTER_REGIONS'),
    'states'     => env('TAX_REGISTER_STATES'),
    'streets'    => env('TAX_REGISTER_STREETS'),
    'boundaries' => env('TAX_REGISTER_BOUNDARIES', true),
    'keep'       => (int) env('TAX_REGISTER_KEEP', 2),
],
```

Dependencies move too: `cboxdk/laravel-geo` goes to `^0.6`, and `cboxdk/tax-resolver
^1.0` is new — the boundary resolver the register itself is built against, so the
engine and the data cannot drift.

## 3. Classes that no longer exist

If you referenced any of these directly — most applications only referenced the
contracts — bind the register adapter instead. The contracts themselves are
unchanged in name and still the seam for your own sources; `ChainTaxRateSource` and
`CachingTaxRateSource` are still there to put one in front.

| Removed | Use instead |
| --- | --- |
| `UsTaxDatasetRateSource`, `UsTaxData\UsTaxDataset` | `Register\Sources\RegisterRateSource` |
| `TedbSoapRateSource`, `TedbRateSource`, `IbericodeVatRateSource`, `RemoteRateSource` | `RegisterRateSource` |
| `StaticTaxRateSource` and `resources/rates.json` | `RegisterRateSource` |
| `StaticProductTaxability`, `UsTaxDatasetTaxability` | `Register\Sources\RegisterTaxability` |
| `StaticNexusThresholds`, `UsTaxDatasetNexus` | `Register\Sources\RegisterNexus` |
| `UsTaxDatasetSourcing` | `Register\Sources\RegisterSourcing` |
| `ArcGisRateSource` | `Register\Sources\RegisterBoundaries` (polygon layers ship in the register) |
| `UsTaxData\TaxabilityDetermination` | `ValueObjects\TaxDetermination` (a determination, not a boolean) |

Scheme and locality constants that used to live on the dataset sources are now on
`Enums\LocalityScheme`.

## 4. Contract signatures

These break a build. Only relevant if you implement or call them yourself — most
applications only bind them.

| Contract | Change |
| --- | --- |
| `TaxRateSource`, `CommodityRateSource` | the category parameter is `TaxClass`, not `TaxCategory` |
| `ProductTaxability` | `isTaxable(Jurisdiction, TaxCategory): bool` → `determine(Jurisdiction, TaxClass, Money $amount, ?DateTimeImmutable $at = null): TaxDetermination` |
| `MarketplaceRules` | `platformOwes(): bool` → `liability(): MarketplaceLiability` (`PlatformOwes`, `SellerCollects`, `Conditioned`) |
| `DeliveryRules` | `included()` → `treatment()`, returning a `DeliveryTreatment` |
| `NexusThresholds`, `SourcingRules` | take an optional `?DateTimeImmutable $at = null`; a one-argument call still means today |
| `ReturnAggregator` | `aggregate($assessments, ?ReturnPeriod $period = null)` |

`RateKind` gained `Exempt`. A `match` over it with no default needs the case; code
comparing `=== RateKind::Zero` to mean "charges nothing" should use
`$kind->isNil()`.

A rate source that cannot answer must now **throw** `RateSourceUnavailable` rather
than return `null`: null meant four different things, and one of them was silently
pricing history at today's rate.

`TaxCategory` still exists but is deprecated and no longer accepted anywhere.
Migrate a stored column with `TaxCategory::toClass()`, which lands on the 56-class
`TaxClass` — the enum the register's bands are actually reachable through.

Other public types whose constructors grew (all appended, so positional calls
survive): `TaxRate` (`components`, `limitedBy`, `provenance`, and it now refuses a
percentage outside 0–100 and components that do not sum), `NexusThreshold`
(operators, `measuredBy`, `obligations`), `TaxQuery`, `TaxAssessment`. Two things
were removed outright: `NexusThreshold::isMet()` — the verdict needs the state's
measuring period and basis, which this package is not told — and
`DefaultRegimeRegistry::withDefaults()` gained five parameters if you call it
directly.

## 5. Numbers that move without any change on your side

These are the ones to look at before you deploy — each changes an amount or a
treatment an application in production is already producing.

- **Collection is gated on registration outside the US.** A supply into a country
  where the seller is neither established nor registered now returns
  `NotRegistered` and charges nothing, where 0.9 charged destination tax. If your
  sellers hold foreign numbers, state them — see
  [seller registrations](../core-concepts/seller-registrations.md). An OSS or IOSS
  registration covers the Union, stated either as `OssStatus` or as a registration
  with the `oss`/`ioss` scheme.
- **`marketplaceFacilitated` now acts outside the US too**, where the register
  publishes a mandate. Where the mandate names conditions — all 26 EU member states'
  Art. 14a rules do — the seller keeps charging and the rate carries
  `RateLimit::MarketplaceLiabilityUnread`.
- **Canada is the federal rate plus the province's share.** Alberta answers 5% (it
  was 0%), the PST provinces answer the combined figure, and an HST province follows
  a federal exemption. A PST is only collected by a seller holding that province's
  permit.
- **US taxability leans taxable.** A category with no published determination in a
  known state defaults to taxable and is flagged `RateLimit::TaxabilityAssumed`,
  where the retired dataset returned an explicit "undetermined" verdict.
- **Intrastate sourcing is applied, not just reported.** Nine states tax an in-state
  sale at the seller's location; pass `SupplyRoute(shipFrom: …)` or the sale stays
  destination-sourced.
- **Per-item caps need the quantity.** Massachusetts' $175 and New York's $110 are
  per article, so two $150 coats on one line are two coats now: set
  `SupplyLine::$quantity` / `TaxQuery::$quantity`, which defaults to 1.
- **Intra-EU B2B goods invoice as an exempt Art. 138 supply**, not an Art. 196
  reverse charge. The treatment is `IntraCommunitySupply`; `isReverseCharge()` still
  answers true for it, so code asking "does the seller charge?" is unaffected, but
  the invoice wording, the return box and the EC Sales List filing change.
- **Exempt is no longer reported as zero-rated.** Where the register files a supply
  as exempt — financial services, insurance, education, most healthcare — the
  treatment is `Exempt` and the rate's kind `RateKind::Exempt`; a zero-rated supply
  stays `ZeroRated`. The amount is 0 either way, and the return box and the input-tax
  deduction are not. Outside the EU a 0% rate used to come back as `Standard`; it is
  now `ZeroRated` or `Exempt` there too. An exempt EU invoice carries an `exempt`
  mention. A seller not registered in the country now gets `NotRegistered` for these
  supplies too, as it already did for standard-rated ones.
- **A service is taxed where it is performed** — hotels, events, works, restaurant
  and passenger transport — for business customers as much as consumers. Without
  `performedAt` the supplier's country is assumed and flagged
  `RateLimit::PerformanceLocationAssumed`.
- **An unvalidated business customer is treated as a consumer** for place of supply.
- **Flat charges are per order, not per line**, so a two-line order stops paying
  Colorado's retail delivery fee twice.
- **UK VAT ID validation is fail-closed**: an HMRC response that does not echo the
  number and name is inconclusive, and tax is charged rather than reverse-charged.
- **Rates carry caveats.** `confidence`, `limitedBy` and `provenance` are populated
  on every rate the register answers; `OrderAssessment::limits()` and
  `needsReview()` collect them for a whole document. An application that blocked on
  "could not determine" in 0.9 should block on `needsReview()` here — a conditioned
  rate is priced at the standard rate rather than refused.

## 6. Worth adopting once you are on it

- **Ask by the register's own category key.** `TaxQuery::$categoryKey` and
  `SupplyLine::$categoryKey` take `services.education` or
  `goods.medical_equipment.prosthetic` — 182 published categories against the 47 the
  `TaxClass` enum can name. An unknown key refuses rather than falling back.
- **`performedAt`** places a service where it is performed (hotels, events, works),
  instead of assuming the supplier's country.
- **Decision facts.** Conditional published rules — Kansas's delivery rules, for one
  — are evaluated against facts you supply on `DeliveryCharge::$facts` under the
  register's own names. An absent fact is unknown, never false, and an unknown
  refuses while naming the facts it needed.
- **Order-level APIs.** `TaxOrder` assesses a document, apportions delivery across
  its lines, splits mixed-rate freight per authority, and reports
  `taxByAuthority()` for a filing.

## Verifying the upgrade against your own history

Rate the same invoices twice — once on 0.9, once here — and compare. Differences
should fall into the categories in §5; anything else is worth reporting. Pin the
release while you do it, so a mid-comparison publication does not move the ground:

```bash
TAX_REGISTER_VERSION=2026.09.22-261 php artisan tinker
```
