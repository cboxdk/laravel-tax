# The platform, for someone arriving cold

The calculation packages and one published register. This is what each one is for, why
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
   └─ data.cboxtax.com  (published)    One register: 80 jurisdictions, 11 regimes.
          ↑
      cadastre                          The engine that reads the authorities.
```

**The engine owns the logic; the register owns the numbers.** That line is load-bearing
in two directions. Legally, `laravel-tax` is MIT and the register is **PolyForm
Internal Use** — you may compute your own tax with it, you may not resell a rate
lookup. Practically, it means a rate change is a data release, not a code release.

**The compiler is not the register.** `cadastre` is the private pipeline that reads
the authorities and publishes; `data.cboxtax.com` is what a consumer syncs from. The
two compiled datasets it replaced — `eu-tax-dataset` and `us-tax-dataset` — are
retired, each on a measurement rather than a decision to stop looking.

## What each repo actually does

| Repo | Owns | Does NOT own |
| --- | --- | --- |
| **laravel-geo** | Countries, subdivisions, localities as typed value objects | Any tax rate or rule |
| **laravel-tax** | Place of supply, reverse charge, taxability gates, rate application, returns aggregation | The rates themselves |
| **laravel-nexus** | Measuring a seller's sales against a state's economic-nexus threshold | Deciding the rate once nexus exists |
| **cadastre** | Reading the authorities directly — statutes, revenue departments, SST boundary files, ArcGIS polygons — and publishing one register with per-fact provenance | Serving determinations; anything that is a tax engine |

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

## Release status

The register integration is on `main` and recorded under `Unreleased` in
`CHANGELOG.md`. Check Git tags and releases for publication status rather than
assuming the current branch has shipped. Versioning and publication are the repo
owner's call; do not tag or cut releases without an explicit request.

## Things that will bite you

**The compiler reads schema 1.x.** It rejects a different major version. Additive
fields can still change the meaning of an answer, so compatibility needs tests
against published documents as well as fixture tests.

**The compiled manifest checks local integrity.** `tax:data:verify` checks installed
file sizes and hashes offline. The compiler writes those hashes from downloaded
content; they are not signatures or independent verification of the publisher.

**A pin overrides the active pointer.** `tax.register.version` controls pricing and
the default sync target. Restart long-running workers after changing releases.
Pruning protects both the active release and the configured pin.

**Positional constructor arguments.** Several value objects gained parameters that
were deliberately appended LAST rather than placed where they belong by meaning,
because slotting one in would silently shift every existing positional caller. Follow
that discipline; the docblocks say so where it applies.

**PHP casts numeric array keys to int.** A map keyed by a rate (`"10"`) or any
numeric-looking string must be annotated `array<array-key, …>`. This has cost three
gate failures.

**The guards are not decoration.** `cadastre` fails its build if a county-resolved
state gains an authority below the county line, if a material share of a state's rates
is about to expire, or if the published register is behind the sources. It
has equivalents for ambiguity drift and schedule health. A red guard means the world
moved, not that the guard is wrong.

## The verification gate

Every repo, before every commit. Never a partial run.

```bash
vendor/bin/pint --test
vendor/bin/rector process --dry-run                          # rector.php says what it skips and why
vendor/bin/phpstan analyse --no-progress --memory-limit=1G   # level 10, larastan; bin, config, src
vendor/bin/pest  # includes the live e2e group
composer audit --no-dev
composer license-check
composer sbom && git diff --exit-code sbom.json
```

`composer qa` runs all but the last; CI runs the SBOM check against the committed
lock. No `@phpstan-ignore`, no baseline, no `assert()` to override inference, no cast
to quiet a type. Fix the cause.

## Where to read next

- [`docs/index.md`](docs/index.md) — the engine's own documentation
- [`docs/coverage/`](docs/coverage/_index.md) — what is covered per jurisdiction, and
  an honest list of what is not
- [`docs/decisions/`](docs/decisions/_index.md) — why things are the way they are,
  including three findings that turned out to be wrong and were corrected
- [`docs/extension-points/`](docs/extension-points/_index.md) — the seams a host binds
