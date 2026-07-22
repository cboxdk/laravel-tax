---
title: US tax dataset (us-tax-data)
weight: 3
description: The compiled us-tax-data dataset is the default US source — state rates, 25-category taxability, economic nexus, and intrastate sourcing — replacing the hardcoded US static tables.
---

# US tax dataset (us-tax-data)

US sales tax is powered by the compiled [`us-tax-data`](https://github.com/cboxdk/us-tax-dataset)
dataset (schema version 3): an authoritative, dated, primary-sourced compilation
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
the common state-level path; the bulky `rates` section (every local record) is read
lazily and only when a rooftop locality is resolved. Fetched sections are cached for
`ttl` seconds. **Pin `location` at a tagged release or a committed local copy** for
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
2. A **rooftop all-in** rate when the jurisdiction carries a `LocalityCode`: the
   state and the matched local record are stacked per the state's rate basis
   (`component` adds the state share; `combined` records are already all-in), at
   `Confidence::Authoritative`.
3. Otherwise the **state rate**, at `Confidence::Derived` — honest that it is the
   state share, not a rooftop all-in figure.

### Rooftop is partial and opt-in

Setting `us_tax_data.rooftop` lets the Geocodio adapter capture a **county FIPS** as
a locality. This is **experimental and off by default**: the dataset's per-state
local codes are heterogeneous (county FIPS for the Streamlined states, comptroller
authority codes for Texas/Alabama, `06:PLACENAME` for California, location ids for
Illinois), and a county FIPS cannot pick a rooftop's city or special-district
records. A faithful all-in rooftop rate needs a point→jurisdiction crosswalk the
dataset does not yet ship; until then, absent a resolved locality the **state rate
applies**. The plumbing (a `LocalityCode` on the geo `Jurisdiction`, and rate
stacking when one is present) is wired end-to-end, so enabling rooftop resolution is
a data question, not a code one.
