---
title: US tax dataset (us-tax-data)
weight: 3
description: The compiled us-tax-data dataset is the default US source — state rates, 25-category taxability, economic nexus, and intrastate sourcing — replacing the hardcoded US static tables.
---

# US tax dataset (us-tax-data)

US sales tax is powered by the compiled [`us-tax-data`](https://github.com/cboxdk/us-tax-dataset)
dataset (schema version 4): an authoritative, dated, primary-sourced compilation
covering all 51 jurisdictions. It is **enabled by default** and **replaces the
hardcoded US entries** that the static tables used to ship — the static snapshot
now carries non-US rates only. The rest of the world is unaffected.

## The four planes it supplies

| Plane | Contract bound to it | What the dataset provides |
| --- | --- | --- |
| Rates | `TaxRateSource` (`UsTaxDatasetRateSource`) | Per-state rate; a reduced rate for categories a state reduces (e.g. grocery); rooftop all-in when a locality is resolved |
| Taxability | `ProductTaxability` (`UsTaxDatasetTaxability`) | Per-state, per-category taxable/exempt across 25 product categories |
| Nexus | `NexusThresholds` (`UsTaxDatasetNexus`) | Per-state economic-nexus dollar/transaction thresholds |
| Sourcing | `SourcingRules` (`UsTaxDatasetSourcing`) | Per-state intrastate origin/destination/mixed sourcing rule |

Each source answers only for the US and defers otherwise: the rate source returns
`null` for non-US jurisdictions (so a composed `ChainTaxRateSource` falls through
to the EU/national sources), and the taxability source delegates non-US — and US
pairs the dataset leaves undetermined — to the static fallback matrix.

## Configuration

```php
// config/tax.php
'us_tax_data' => [
    'enabled'  => env('TAX_US_DATASET', true),
    'location' => env('TAX_US_DATASET_LOCATION', 'https://raw.githubusercontent.com/cboxdk/us-tax-dataset/main'),
    'ttl'      => (int) env('TAX_US_DATASET_TTL', 86400),
    'rooftop'  => env('TAX_US_DATASET_ROOFTOP', false),
],
```

`location` is an `http(s)` base URL (the public dataset mirror) or a local
directory, under which the split files live at `by-section/<section>.json`. Only
the small `baseline`, `taxability`, `nexus` and `sourcing` sections are fetched for
the common state-level path; the bulky `rates` section (every local record) and a
state's `boundaries/US-XX.json` index are read lazily and only when a rooftop
locality is resolved. Fetched sections are cached for `ttl` seconds. **Pin `location` at a tagged release or a committed local copy** for
an offline/deterministic build. Disabling it falls back to the static snapshot (and,
for taxability/nexus, the shipped static US tables).

Deny-by-default holds throughout: any transport/read/parse failure yields a
`null`/empty result, so the engine denies rather than guessing.

## Rate precision: state level, with reduced-rate and rooftop refinements

The dataset carries every local rate, but the engine resolves jurisdictions to the
**state** (see [geocoding](../extension-points/geocoding.md)). So the rate source
returns:

1. A **reduced rate** when a state reduces a category (e.g. Missouri groceries at
   1.225%) — a product rule applied whatever the location.
2. A **rooftop all-in** rate when the jurisdiction carries a `LocalityCode`: EVERY
   applicable local record is summed and the state share added per the state's rate
   basis (`component` adds it; `combined` records are already all-in), at
   `Confidence::Authoritative`. Which records apply comes from the boundary index —
   see [below](#rooftop-zip4-into-the-boundary-index).
3. Otherwise the **state rate**, at `Confidence::Derived` — honest that it is the
   state share, not a rooftop all-in figure.

### Rooftop: ZIP+4 into the boundary index

Setting `us_tax_data.rooftop` lets the Geocodio adapter capture the address's full
**ZIP+4** as a locality (scheme `zip9`, e.g. `66101-3064`). A ZIP+4 is a *postal*
key, not a taxing authority — the dataset's **boundary index** turns it into the
authorities that actually apply, and the rate source sums **every** local record
they resolve, then adds the state share per the state's `rateBasis`.

That summing is the whole point. Local records are components, and which of them
apply differs by address in a way no rule can predict:

| Address | Index returns | Rate |
| --- | --- | --- |
| `701 N 7th St, Kansas City KS` → `66101-3064` | county `209` **and** city `36000` | 6.5% + 1.0% + 1.625% = **9.125%** |
| `400 Broad St, Seattle WA` → `98109-4607` | city `63000` only | 6.5% + 4.05% = **10.55%** |

Both come out of the same code path. Taking the most specific record alone would be
right for Seattle and 100 bp low for Kansas City; there is no per-state rule to
encode, because the boundary file carries it.

The indexes are published: all 24 member states, **5.4 MB gzipped**, mirrored to
`boundaries/US-XX.json.gz` and refreshed quarterly, each verifiable against
`boundaries/manifest.json`.

Three limits remain:

**Coverage is the 24 SST member states, plus two resolved by polygon.**

California and New Mexico publish no boundary file but do publish an official
ArcGIS service of **polygons** carrying the jurisdiction and its rate, which
`ArcGisRateSource` queries by point — finer than a ZIP+4, since it is real geography
rather than a postal proxy for it. Verified against both services: Los Angeles City
Hall resolves 9.75%, San Francisco 8.625%, Albuquerque 7.625%, Santa Fe 8.1875%.

Because a jurisdiction carries exactly one locality, the geocoder emits a **point**
for those two states and a **ZIP+4** everywhere else.

That leaves **six** states with no rooftop path from anything published: Texas,
Arizona, Colorado, Louisiana, Missouri, Illinois, Alabama and Alaska resolve to the
state rate. Texas is the notable one — it *does* produce an SST-formatted address
dataset, but behind an audited account portal that labels the data sensitive, so a
publicly redistributable index cannot be derived from it.

**A ZIP+4 or a coordinate is required, so a geocoder becomes load-bearing.** Absent an add-on — or
where Geocodio returns several for one address, which is refused rather than picked
— no locality is attached and the state rate applies.

**Address-range precision is not indexed.** The boundary files carry street-level
`A` rows (570k of Kansas' 684k), but resolving those needs a parsed street address
rather than a ZIP+4, so the index carries the whole-ZIP and ZIP+4 rows only.

Absent a resolved locality the **state rate applies** at `Confidence::Derived`,
which is honest about what it is; a resolved one is `Confidence::Authoritative`.
