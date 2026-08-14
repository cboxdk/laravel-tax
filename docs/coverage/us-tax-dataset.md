---
title: US tax dataset (us-tax-data)
weight: 3
description: The compiled us-tax-data dataset is the default US source — state rates, 25-category taxability, economic nexus, and intrastate sourcing — replacing the hardcoded US static tables.
---

# US tax dataset (us-tax-data)

US sales tax is powered by the compiled [`us-tax-data`](https://github.com/cboxdk/us-tax-dataset)
dataset (schema version 4), a dated compilation covering all 51 jurisdictions. It is **enabled by default** and **replaces the
hardcoded US entries** that the static tables used to ship — the static snapshot
now carries non-US rates only. The rest of the world is unaffected.

## Where the numbers come from, honestly

The two planes have **different provenance**, and the difference matters because
most callers get the weaker one.

| Plane | Source | Primary? |
| --- | --- | --- |
| **Local rate records** | The SST Governing Board's own boundary/rate files for 24 states, plus each state's revenue department directly — CDTFA, AZ DOR, FL DOR, HI, IL, LA, MO DOR, MS DOR, NY, PA, AL DOR, and the ARSSTC for Alaska | **Yes** — these are the taxing authorities |
| **The 51 state-level rates** | A single Tax Foundation table, *State and Local Sales Tax Rates, Midyear 2026* | **No** — a think-tank compilation |
| Taxability, nexus, sourcing | Practitioner compilations, cross-checked; see the pages for each | **No** |

So the rooftop path rests on primary sources; the state rate — which is what you
get in the [12 states with no rooftop path](#the-12-states-with-local-tax-and-no-rooftop-path)
— rests on a secondary one. That is the same footing the
[EU VAT dataset](eu-tax-dataset.md) is on, and it is described the same way there.

Treat the state rates as a good, refreshable default and re-verify against each
state's own guidance before relying on them for a filing.

## Licence — the engine is MIT, the data is not

This package is MIT. The dataset it fetches by default is **not**:
[`cboxdk/us-tax-dataset`](https://github.com/cboxdk/us-tax-dataset) is published
under the **PolyForm Internal Use Licence 1.0.0**, and it is **enabled by
default** — so a fresh install is already using it.

| You may | You may not, without a separate licence from Cbox |
| --- | --- |
| Use it for your own business, commercially | Redistribute the dataset |
| Compute the tax on your own sales | Bundle it into something you ship |
| Charge your customers that tax | Offer a rate lookup, an API, or a product feature that gives your customers the rates |

The line the licence draws is between *charging your customers tax you computed
with this data* — which is the intended use and entirely fine — and *telling your
customers what the rates are*, which is distribution and is not covered.

If you are building the latter, set `TAX_US_DATASET=false` and bind your own
`TaxRateSource`. Note what that costs you: see
[the disabled-dataset path](#configuration) below, which is a narrow escape hatch
rather than an equivalent mode.

The [EU VAT dataset](eu-tax-dataset.md) is a different story — that one is ours, and
documented as such.

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
an offline/deterministic build.

**Disabling it is a narrow escape hatch, not an equivalent mode.** Rates fall back to
the static snapshot and nexus to the shipped 51-state table, but **taxability does
not**: the static matrix carries only the curated per-state `digital_service` map
plus whatever overrides you supply. General tangible goods (`Standard`) still
resolve, and every other US category **refuses** — clothing, grocery, candy,
prewritten software and the rest all raise `UnresolvedProductTaxability`.

That is deliberate. The alternative is inventing 25 categories × 51 states of
determinations this package has no source for, which is the one thing it will not
do. If you need US taxability offline, mirror the dataset to a local directory and
point `location` at it rather than disabling it.

Deny-by-default holds throughout: any transport/read/parse failure yields a
`null`/empty result, so the engine denies rather than guessing.

## Price thresholds are dollar figures, and are treated as such

Three states exempt clothing below a per-item price — Massachusetts at $175, New
York at $110, Rhode Island at $250 — and two mechanics apply once an item reaches
the line. Massachusetts and Rhode Island tax only the amount **over** it; New York
taxes the **whole item**, the first $110 included. The dataset publishes the
mechanic alongside the figure, and a rule carrying one without the other is refused
rather than guessed.

Those figures are **USD**, because they are numbers in state statutes. Assessing a
threshold category against a line denominated in something else throws
`ThresholdCurrencyMismatch`:

```php
// A ¥20,000 jacket shipped to New York.
$calculator->assess($query);   // throws ThresholdCurrencyMismatch
```

Comparing them would need an exchange rate on the supply date, and the rate chosen
would decide whether the line is taxed at all — so this package will not pick one.
Convert the line to USD before assessing it; your accounting already has a rate, and
it is the right one to use.

The refusal is scoped to categories whose taxability actually turns on price.
Everything else is assessed in whatever currency you bill in.

## Rate precision: state level, with reduced-rate and rooftop refinements

The dataset carries every local rate, but the engine resolves jurisdictions to the
**state** (see [geocoding](../extension-points/geocoding.md)). So the rate source
returns:

1. A **reduced STATE SHARE** when a state reduces a category (Missouri groceries at
   1.225%, Tennessee's at 4%). This replaces the state share only — it does not
   replace the local ones, because both states' own guidance is explicit that local
   sales taxes still apply to food. With a rooftop locality resolved, the reduced
   share is stacked with each locality's **food rate** (`foodDrugRate`, which the
   dataset carries separately from the general local rate — a Tennessee city may
   levy 2.75% generally and 2.25% on food, and some exempt food locally). Without
   one, the reduced share alone is returned at `Confidence::Derived`, exactly as the
   general state rate is: a partial answer, labelled as one.
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

**Boundary-index coverage is the 24 SST member states**, plus two resolved by
polygon and four by county.

California and New Mexico publish no boundary file but do publish an official
ArcGIS service of **polygons** carrying the jurisdiction and its rate, which
`ArcGisRateSource` queries by point — finer than a ZIP+4, since it is real geography
rather than a postal proxy for it. Verified against both services: Los Angeles City
Hall resolves 9.75%, San Francisco 8.625%, Albuquerque 7.625%, Santa Fe 8.1875%.

Florida, Pennsylvania, Hawaii and Virginia need no index at all — see
[below](#four-states-need-only-the-county-and-get-an-exact-rate-without-a-boundary-file).

Because a jurisdiction carries exactly one locality, the key differs by state: a
**point** for CA/NM, a **county** for FL/PA/HI/VA, and a **ZIP+4** everywhere else.

### Four states need only the county, and get an exact rate without a boundary file

**Florida, Pennsylvania, Hawaii and Virginia** are resolved exactly with no boundary
index at all, because in those four nothing can tax below the county line. Resolve
the county and you have the whole local share.

| State | Local authority | Reach |
| --- | --- | --- |
| **FL** | Discretionary sales surtax, 0–2% | all 67 counties |
| **PA** | Allegheny 1%, Philadelphia 2% | the only two that exist |
| **HI** | County GET surcharge, 0.5% | 4 of 4 adopters |
| **VA** | Regional additions, +0.7 / +1.0 / +1.7% | all 39 localities in a band |

**Virginia is on the list for a different reason from the other three.** Its cities
are not small — they are *independent*: under Virginia law a municipality
incorporated as a city is not part of any county at all, which is why the Census
counts all 38 as county-equivalents. So a Virginia city is not something sitting
below a county; it is one. The 5.3% state rate already contains the mandatory
statewide 1% local levy, and the 39 records are the regional additions on top,
checked against the Department of Taxation's own bands.

That creates one trap worth knowing about, because Virginia has both a Fairfax
County and an independent city of Fairfax — and likewise Franklin, Richmond and
Roanoke. They tax different ground at different rates. The name match is ordered so
the full name is tried before any shortened form, which keeps each pair apart; a
pair it genuinely could not tell apart would resolve to nothing and fall back to the
state rate rather than putting one authority's rate on the other's territory.

A geocoder returns the county on every US result with no add-on, so this path is
**not** behind the `rooftop` opt-in: it costs nothing extra and gating it would
leave those states charging the bare state share for no benefit. A Gainesville
address prices at 7.5% (6% state + 1.5% county) at `Confidence::Authoritative`.

Two details worth knowing:

**A county that levies nothing is an answer, not a gap.** Citrus County's surtax is
0%, so a Citrus address prices at the 6% state rate — but `Authoritative`, because
that IS the all-in rate there. A county that fails to MATCH resolves to the same
6% at `Derived`, and the confidence is the only thing telling the two apart.

**Hawaii's figure is the legal rate, not the receipt rate.** The GET is a tax on
the seller's gross receipts, and it may itself be taxed when passed on, so a
Honolulu customer sees a higher percentage than the 4.5% owed. This package
computes the liability; the gross-up a seller applies to recover it is a separate
calculation and is deliberately not modelled.

Most of Virginia levies no regional addition at all, and 5.3% is genuinely the whole
rate there — but that is reached by the county failing to match, which is "unknown"
rather than "nothing applies", so it is labelled `Derived`.

**South Carolina looks like it belongs here and does not.** 46 of its 47 local
authorities are counties — but Myrtle Beach levies a 1% Tourism Development tax on
top of Horry County's, and the statute lets other qualifying municipalities adopt
one. Pricing from the county alone there would under-charge, so SC stays on the
list below. `bin/check-county-resolved.php` in the dataset repo fails the build if
FL, PA, HI or VA ever grows the same exception — including a Virginia *town*, which
unlike a Virginia city does sit inside a county.

### The 12 states with local tax and no rooftop path

37 states levy a local sales tax. 30 of them now have a rooftop-accurate path (the
24 SST boundary files, CA/NM by polygon, and FL/PA/HI/VA by county). That leaves
**twelve** where nothing published resolves below the state line:

**AL · AZ · CO · ID · IL · LA · MO · MS · NY · SC · TX**, and **AK** (see below).

In eleven of those the **state rate applies** at `Confidence::Derived` — an honest
floor, but a floor: Louisiana's state share is 4.45% against a combined rate that
reaches 11.45%, Alabama's 4% against up to ~12.5%, Colorado's 2.9% against ~11.2%.
**Check `$assessment->rate->confidence` before relying on a US figure in one of
these states**, and bind a commercial adapter where the local share matters.

New York and Illinois are worth calling out for SaaS sellers specifically: both tax
digital services, and both are in this list.

Colorado is the most tractable of the twelve: its Department of Revenue runs a
free address-level GIS service, and CRS 39-26-105.2 holds a vendor that relies on it
harmless in an audit for errors in the data. Its API key is issued per business, so
it belongs in a host-configured adapter rather than in the dataset — the same shape
as `ArcGisRateSource`. Not built yet.

**Alaska is different and now refuses outright.** It levies no *state* sales tax
while its boroughs and cities levy their own (Juneau 5%, Wrangell 7%). Alaska
removed its statutory cap on local rates, so **do not hard-code a ceiling**. A
state share of 0% there is not a conservative floor — it is an affirmative "no tax due"
on a supply that is taxed — so the source returns null and the engine raises
`UnresolvedTaxRate` rather than charging zero. Bind the ARSSTC remote-seller rate
sheet to serve Alaska.

Texas is the notable near-miss: it *does* produce an SST-formatted address dataset,
but behind an audited account portal that labels the data sensitive, so a publicly
redistributable index cannot be derived from it.

**A ZIP+4 or a coordinate is required, so a geocoder becomes load-bearing.** Absent an add-on — or
where Geocodio returns several for one address, which is refused rather than picked
— no locality is attached and the state rate applies.

**Address-range precision is not indexed.** The boundary files carry street-level
`A` rows (570k of Kansas' 684k), but resolving those needs a parsed street address
rather than a ZIP+4, so the index carries the whole-ZIP and ZIP+4 rows only.

Absent a resolved locality the **state rate applies** at `Confidence::Derived`,
which is honest about what it is; a resolved one is `Confidence::Authoritative`.
