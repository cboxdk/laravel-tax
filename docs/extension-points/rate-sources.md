---
title: Rate sources
weight: 1
description: Bind a live TaxRateSource — TEDB, SST or a commercial adapter.
---

# Rate sources

The engine owns the calculation; the **rate number** is the one thing it sources.
Bind your own `Contracts\TaxRateSource` to replace the default static rates:

```php
use Cbox\Tax\Contracts\TaxRateSource;

$this->app->singleton(TaxRateSource::class, fn () => new TedbRateSource(/* ... */));
```

A source returns a `TaxRate` (percentage, band, provenance, confidence) for a
jurisdiction and category, or `null` when it has no rate — in which case the
engine raises `UnresolvedTaxRate` rather than assuming 0%.

Recommended defaults per region:

| Region | Source |
| --- | --- |
| EU | the EU Commission's TEDB, called live (shipped adapter, no API key), or the MIT-licensed `ibericode/vat-rates` dataset |
| US (SST states) | the SST Rate & Boundary files |
| US (non-SST / home-rule), Canada provinces | a commercial adapter |

Rates are **data that changes** — treat them as versioned/refreshable, never
hard-coded. Record the `source` and `confidence` on each assessment so a coarse
fallback is never mistaken for an authoritative rate.

## Category-aware rates (reduced / zero bands)

`rateFor()` receives the supply's **`TaxCategory`**, and the shipped sources honour
it: a source may carry per-(jurisdiction, category) **reduced or zero bands** and
return one instead of the standard rate. Pass bands to `StaticTaxRateSource` keyed
by `"<jurisdiction>:<category>"`:

```php
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\ValueObjects\RateBand;

new StaticTaxRateSource(rates: null, bands: [
    'FR:digital_service' => new RateBand('5.5', RateKind::Reduced),
    'DK:digital_service' => new RateBand('0', RateKind::Zero),
]);
```

> **No national reduced-rate table ships.** The default snapshot carries **only
> standard rates** — the package will not fabricate reduced bands, which are DATA
> that must come from an authoritative feed. Enable the [live TEDB
> source](#the-eu-tedb-service-tedbsoapratesource) for EU bands, supply your own,
> or bind a TEDB export whose entries carry a `bands` map. A category with no band
> resolves the standard rate.

## The EU TEDB service (`TedbSoapRateSource`)

The Commission's **Taxes in Europe Database** is the authoritative EU rate source,
and this adapter calls it directly. **There is nothing to download** — TEDB
publishes no CSV/JSON export, and its `VatRetrievalService` SOAP endpoint plus the
web UI are the only ways to get the data. The service needs **no API key and no
registration**:

```php
// config/tax.php  (or .env: TAX_TEDB_LIVE=true)
'tedb' => [
    'live' => env('TAX_TEDB_LIVE', false),
    'ttl'  => (int) env('TAX_TEDB_TTL', 86400),
],
```

Enabled, the engine composes `ChainTaxRateSource(TEDB → static snapshot)` and
caches each member state's parsed rate table for `ttl` seconds — one request per
country per TTL, not one per assessment.

### What it resolves, and what it refuses

- **Standard rates** for all 27 member states, from the single `DEFAULT` entry.
- **Reduced and zero bands** for `grocery`, `prepared_food`, `books`, `newspapers`,
  `magazines`, `medical_devices` and `prescription_drugs` — but **only where TEDB
  resolves that category to one rate for that country**.

That last condition carries the weight. TEDB routinely carries a category at
several rates at once because the sub-scopes differ: French pharmaceuticals sit at
2.1%, 5.5% **and** 10%, and Irish books are zero-rated in print while their
electronic form is 9%. Nothing in the response says which applies to a given
supply, so the band is dropped and the **standard rate** applies. Over-charging is
recoverable; silently applying the wrong reduced rate is not.

Where a state splits a category itself, its own split wins: Poland rates newspapers
separately at 8%, so that survives, while Sweden files newspapers under the broader
"books, newspapers and periodicals" heading and resolves from there.

### Determinations for the splits TEDB resolves in prose

Some splits are only apparent. Ireland's 9% "foodstuffs" rate is *restaurant,
canteen and takeaway food*; its 13.5% "medical equipment" rate is *repairs* to
equipment. The competing rate belongs to a different product class, and TEDB's own
scope note says so — so the band is determined, with that note as the basis:

| Country | Category | Rate | Because TEDB's note says |
| --- | --- | --- | --- |
| IE | `grocery` | 0% | the competing 9% is "Restaurant food, food served in canteens, and take away food" |
| IE | `books` | 0% | "The Zero Rate applies to newspapers, printed books, e-books, audiobooks"; 9% is brochures and catalogues |
| IE | `newspapers` | 0% | the zero rate names newspapers explicitly |
| IE | `magazines` | 9% | "9% applies to periodicals (in printed form or electronically supplied)" — unlike books and newspapers |
| IE | `medical_devices` | 0% | the competing 13.5% is "Repairs to medical equipment" — a service |
| IE | `prescription_drugs` | 0% | 0% is "Human Oral Medicine…"; 13.5% is non-oral contraceptives |
| FR | `prescription_drugs` | 2.1% | "For reimbursed pharmaceutical products"; 10% is non-reimbursed, 5.5% sanitary protection |
| HR | `prescription_drugs` | 5% | "medicines which have the approval of the competent authority"; 13% is menstrual products |
| BE | `prescription_drugs` | 6% | "medicinal products registered as medicines"; the 0% is human organs and blood |
| EL | `prescription_drugs` | 6% | "medicaments … of tariff heading 3003 and 3004 and vaccines"; the 0% is Covid-19 vaccines only |
| BE | `books` `newspapers` `magazines` | 6% | "Newspapers, periodicals and books (digital and on paper)"; the 0% entries are library loans by non-profits |

Two properties keep these honest. A determination is consulted **only when TEDB is
ambiguous** — a state reporting one rate is never overridden. And it is applied only
while the rate it names is **still one TEDB returns**: if a member state changes the
split, the determination stops matching and the band is refused rather than shipped
stale. It self-invalidates instead of quietly going wrong.

### The splits that stay open

The rest are not curatable at this granularity, because the category genuinely spans
several rates by product type. Hungary rates meat, fish, milk and eggs at 5% and
dairy desserts, flavoured milk and cereals at 18% — both are groceries, and no
single number is right:

`grocery` in **AT BE EL HU IT PL PT SK** · `prepared_food` in **SK** ·
`medical_devices` in **CY EL IT** · `prescription_drugs` in **IT MT PL** ·
`newspapers` in **HR** · `books` and `magazines` in **PL**

These resolve to the standard rate. Closing them needs a finer product category —
CN-code granularity — not more curation.

Categories with no confident equivalent — digital services and e-publications above
all, which several states fold into other headings — are **not mapped at all**
rather than guessed.

### Two quirks worth knowing

- TEDB spells Greece **`EL`**, not the ISO `GR`, and rejects the *entire* request
  with `TEDB-ERR-2` if any code is unknown. The adapter translates before calling.
- A SOAP fault answers HTTP 500. Any fault, timeout or unparseable body yields
  `null`, so a composed chain falls through instead of guessing.

## The EU VAT feed (`IbericodeVatRateSource`)

`IbericodeVatRateSource` binds a **real, public, MIT-licensed** EU VAT-rate dataset
— the community-maintained
[`ibericode/vat-rates`](https://github.com/ibericode/vat-rates) feed
(`https://raw.githubusercontent.com/ibericode/vat-rates/master/vat-rates.json`). Its
source, license, shape and honest-provenance notes are documented in
[EU VAT rate feed](../coverage/eu-vat-feed.md).

Enable it and the provider composes `ChainTaxRateSource(EU feed → static snapshot)`:

```dotenv
TAX_EU_VAT_FEED=true
# Optional: pin to a mirror or a TEDB export.
# TAX_EU_VAT_URL=https://your-mirror.example/vat-rates.json
```

It reads the real dataset shape (`items` keyed by country → date-effective rate
periods) and selects the period **in force** at the assessment date. The dataset's
reduced tiers are not category-labelled, so it resolves the **standard** rate by
default; pass an authoritative `TaxCategory → tier` map to surface a reduced tier:

```php
use Cbox\Tax\RateSource\IbericodeVatRateSource;

new IbericodeVatRateSource(
    $app->make(\Illuminate\Http\Client\Factory::class),
    config('tax.eu_vat.url'),
    categoryTiers: ['digital_service' => 'reduced1'], // operator-asserted mapping
);
```

## The TEDB adapter

`TedbRateSource` reads a **TEDB-derived dataset** — the EU Commission's *Taxes in
Europe Database* (`VatRetrievalService`), transformed to the JSON shape below. Its
location is **config-driven** (`tax.tedb.url`), an `http(s)` URL **or** a local file
path; the package ships **no endpoint**, so you must point it at a real export.

Set `tax.tedb.url` (env `TAX_TEDB_URL`) and the provider composes
`ChainTaxRateSource(TEDB → static snapshot)` automatically — TEDB is authoritative,
the static snapshot is the fallback. Unconfigured, the plain static snapshot stays
the zero-config default.

Documented dataset shape:

```json
{
  "version": "2026-07-01",
  "rates": {
    "DK": { "standard": "25" },
    "FR": { "standard": "20", "bands": { "digital_service": { "rate": "5.5", "kind": "reduced" } } }
  }
}
```

Each country entry's `standard` is the standard rate; an optional `bands` map keys
reduced/zero rates by `TaxCategory` value (`kind` ∈ `reduced` | `zero`). A missing
country, an unreadable source, or malformed JSON yields `null` so the engine denies
(and the chain falls back to the static snapshot) rather than guessing. For a URL
source, wrap it in `CachingTaxRateSource` to avoid a request per lookup.

## Composing sources

The package ships composable sources so you can assemble a live feed with a safe
fallback:

- **`StaticTaxRateSource`** — the built-in map (default binding); accepts optional
  reduced/zero `bands`.
- **`IbericodeVatRateSource`** — reads the real MIT-licensed `ibericode/vat-rates`
  EU dataset (URL or file), date-effective; auto-wired to a `ChainTaxRateSource`
  fallback when `tax.eu_vat.enabled` is true.
- **`TedbRateSource`** — reads a normalised TEDB-derived dataset (URL or file);
  auto-wired to a `ChainTaxRateSource` fallback when `tax.tedb.url` is set.
- **`RemoteRateSource`** — fetches a generic JSON country→rate feed (number,
  `{standard}`, or `{standard, bands}`); one request per lookup, so wrap it in caching.
- **`CachingTaxRateSource`** — caches the current rate from an inner source; a
  date-specific lookup bypasses the cache.
- **`ChainTaxRateSource`** — tries sources in order, first hit wins.

```php
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\RateSource\{ChainTaxRateSource, CachingTaxRateSource, TedbRateSource, StaticTaxRateSource};

$this->app->singleton(TaxRateSource::class, fn ($app) => new ChainTaxRateSource([
    new CachingTaxRateSource(
        new TedbRateSource($app->make(\Illuminate\Http\Client\Factory::class), config('tax.tedb.url')),
        $app->make(\Illuminate\Contracts\Cache\Repository::class),
    ),
    new StaticTaxRateSource, // fallback
]));
```

> The adapters implement the documented feed shape; point them at a source you
> trust (the EU TEDB feed, the SST files transformed to JSON, a commercial adapter)
> and verify the data before relying on it in production.
