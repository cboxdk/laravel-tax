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
   `Confidence::Authoritative`. **No shipped geocoder produces a matching locality
   yet** — see [below](#rooftop-is-partial-and-opt-in) — so this path is reachable
   only by supplying a `LocalityCode` in the state's own key shape yourself.
3. Otherwise the **state rate**, at `Confidence::Derived` — honest that it is the
   state share, not a rooftop all-in figure.

### Rooftop is partial and opt-in

Setting `us_tax_data.rooftop` lets the Geocodio adapter capture a **county FIPS** as
a locality. It is **experimental and off by default**, and three separate things
must be solved before it yields a trustworthy all-in rate.

**1. The locality code does not match the dataset's keys.** Geocodio returns a
state-prefixed `county_fips` (`53033` for King County, WA) while the dataset keys
that state's county records as `033`. The lookup therefore finds nothing and falls
back to the state rate — so today rooftop resolution is inert rather than wrong.
Stripping the two-digit state prefix is what makes the key match.

**2. The dataset's local codes are heterogeneous**, so no single identifier reaches
every state. Probing Geocodio against the compiled dataset:

| Dataset key shape | States | Derivable from Geocodio? |
| --- | --- | --- |
| FIPS county (`033`) and place (`63000`) | WA UT TN WI KS NE ND NV WY OH NC AR GA IA MN OK SD VT WV | ✅ `county_fips` / `place.fips` minus the state prefix |
| `<stateFips>:<PLACENAME>` (`06:OAKLAND`) | CA | ✅ from `place.name` |
| `US-XX:<County name>` | FL HI MS PA SC VA | ⚠️ county name matches; city-keyed rows and NYC do not |
| Own authority codes (`2109064`, `7001`, `9001`, `AJ`) | TX AL AK AZ | ❌ needs a crosswalk |
| Composite ids (`048-0002-8`, `00000-001-000`, `09-509`, `acadia-parish:A`) | IL MO NM LA | ❌ needs a crosswalk |
| `county:<Name>` | CO | ❌ curated names, no code |

**3. Intra-local stacking semantics differ per state and the dataset does not
encode them.** `rateBasis` says whether the *state* share is included in a local
record; it says nothing about whether a county and a city record stack with each
other — and they demonstrably differ:

- **Washington** — King County `033` is 3.8%, Seattle `63000` is 4.05%. The place
  record is the *total* local rate; adding both would give 14.35%.
- **Kansas** — Wyandotte County `209` is 1.0%, Kansas City `36000` is 1.625%. Here
  they **do** stack: 6.5% + 1.0% + 1.625% = the 9.125% Kansas City actually levies.

The two states' records are byte-for-byte indistinguishable (both `level: "local"`),
so the rate source cannot tell which rule applies. Picking one record and adding it
to the state rate — what `UsTaxDatasetRateSource` does today — is right for
Washington and 100 bp low for Kansas.

Special tax districts close the gap for good: Kansas City alone has six, pushing
local rates to 10.125–11.125%, and a district is a polygon that no point identifier
selects. A faithful rooftop rate therefore needs boundary data plus per-state
stacking rules, not just a resolved locality. Until then, absent a resolved locality
the **state rate applies** at `Confidence::Derived`, which is honest about what it
is.
