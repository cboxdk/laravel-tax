# The platform, for someone arriving cold

Five repositories and two published data mirrors. This is what each one is for, why
the split exists, and the handful of decisions you need to know before changing
anything.

## The split, and why

```
laravel-geo      jurisdictions          ISO reference data. No tax knowledge.
   ↑
laravel-tax      the engine             Decides whether and how to tax. MIT.
   ↑  ↑
   │  laravel-nexus                     Has this seller crossed a state's threshold?
   │
   ├─ eu-tax-dataset    (published)     What each EU member state charges.
   └─ us-tax-dataset    (published)     What each US state and locality charges.
          ↑                    ↑
      eu-tax-data          us-tax-data  The ETL that builds and publishes them.
```

**The engine owns the logic; the datasets own the numbers.** That line is load-bearing
in two directions. Legally, `laravel-tax` is MIT and the datasets are **PolyForm
Internal Use** — you may compute your own tax with them, you may not resell a rate
lookup. Practically, it means a rate change is a data release, not a code release.

**The ETL repos are not the datasets.** `eu-tax-data` and `us-tax-data` are private
pipelines; they compile and push to the public mirrors `cboxdk/eu-tax-dataset` and
`cboxdk/us-tax-dataset`, which is what a consumer's `location` config points at.

## What each repo actually does

| Repo | Owns | Does NOT own |
| --- | --- | --- |
| **laravel-geo** | Countries, subdivisions, localities as typed value objects | Any tax rate or rule |
| **laravel-tax** | Place of supply, reverse charge, taxability gates, rate application, returns aggregation | The rates themselves |
| **laravel-nexus** | Measuring a seller's sales against a state's economic-nexus threshold | Deciding the rate once nexus exists |
| **eu-tax-data** | Querying the Commission's TEDB service, resolving ambiguity, publishing dated windows | Anything US |
| **us-tax-data** | SST boundary files, per-state revenue departments, ArcGIS polygons, curated overlays | Anything EU |

## The five ideas everything else follows from

**1. Deny rather than guess.** A missing rate returns null and the engine refuses the
line. There is no "assume 0%" anywhere, and adding one would be the single most
damaging change you could make. Over-charging is recoverable; under-charging surfaces
in an audit years later.

**2. Every number carries where it came from.** `TaxRate` has a `source` and a
`Confidence`. A state-level fallback is never dressed up as a rooftop-exact figure.

**3. Ambiguity is published, not resolved.** When a source rates one heading several
ways at once — Hungarian foodstuffs are 5% and 18% simultaneously — the dataset
publishes both and the engine charges the standard rate at reduced confidence. It does
not pick. A caller can then resolve it exactly by supplying the supply's CN or CPA
code, which the source itself scopes each rate to.

**4. The supply's date decides everything.** Rates, taxability, exemption validity,
whether a marketplace-facilitator rule was in force, whether a sales tax holiday was
running. A credit note against a March invoice reprices at March's law.

**5. What limited an answer is reported, not just how good it was.** `Confidence` says
how much to trust a figure; `RateLimit` says what was missing and the one step that
closes it. A warning nobody can act on is one everybody filters out.

## Live branches, as of 2026-08-15

Nothing below is tagged or released. **Versioning and publication are the repo
owner's call** — do not tag, do not cut releases.

| Repo | Branch | Carries |
| --- | --- | --- |
| laravel-tax | `eu-dataset-source` | EU dataset source, county resolution, CN/CPA commodity scopes, marketplace facilitator, sales tax holidays, `ProductCatalogue`, `RateLimit`. Would be **v0.11.0** — breaking: two third-party rate sources removed, `EuTerritories::for()` signature changed |
| eu-tax-data | `territory-rates` | Territory rates (Madeira, the Azores), `REGION` excluded from the product pipeline, CN/CPA scopes published |
| us-tax-data | `county-resolution` | County-resolution guard, marketplace-facilitator dates, the sales tax holiday section |
| laravel-nexus | `main` | — |
| laravel-geo | `main` | — |

## Things that will bite you

**The datasets are verified before they are believed.** Both readers check a sha256
per section against the published manifest and refuse a schema version they were not
written for. If you change the published shape, that is a schema decision: **additive
changes must NOT bump `schemaVersion`**, because the reader refuses on mismatch and a
bump locks out every existing install.

**A new section is additive; a new field inside an existing one may not be.** Adding
`files.sections.holidays` was safe. Changing the shape of `rates` would not be.

**Positional constructor arguments.** Several value objects gained parameters that
were deliberately appended LAST rather than placed where they belong by meaning,
because slotting one in would silently shift every existing positional caller. Follow
that discipline; the docblocks say so where it applies.

**PHP casts numeric array keys to int.** A map keyed by a rate (`"10"`) or any
numeric-looking string must be annotated `array<array-key, …>`. This has cost three
gate failures.

**The guards are not decoration.** `us-tax-data` fails its build if a county-resolved
state gains an authority below the county line, if a material share of a state's rates
is about to expire, or if the published mirror is behind the sources. `eu-tax-data`
has equivalents for ambiguity drift and schedule health. A red guard means the world
moved, not that the guard is wrong.

## The verification gate

Every repo, before every commit. Never a partial run.

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G   # level max, larastan
vendor/bin/pest
composer audit --no-dev
composer license-check
```

No `@phpstan-ignore`, no baseline, no `assert()` to override inference. Fix the cause.

## Where to read next

- [`docs/index.md`](docs/index.md) — the engine's own documentation
- [`docs/coverage/`](docs/coverage/_index.md) — what is covered per jurisdiction, and
  an honest list of what is not
- [`docs/decisions/`](docs/decisions/_index.md) — why things are the way they are,
  including three findings that turned out to be wrong and were corrected
- [`docs/extension-points/`](docs/extension-points/_index.md) — the seams a host binds
