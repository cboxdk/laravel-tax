# Changelog

All notable changes to `cboxdk/laravel-tax` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html) (`0.x`:
minor bumps may carry additive features; patches are fixes and docs).

## [0.3.1] - 2026-08-04

### Fixed

- **Docs described the pre-dataset world.** `coverage/supported.md` still titled the
  US regime "LOGIC ONLY, not production-ready" and listed local (rooftop) rates as
  "❌ only illustrative state base rates" — directly contradicting
  `coverage/us-tax-dataset.md` in the same folder, which documents the compiled
  us-tax-data dataset as bound **by default** for rates, taxability, nexus and
  sourcing. The US sections now state the real position: dataset-backed across all
  51 jurisdictions, with the remaining limitation being **precision, not coverage**
  (jurisdictions resolve to the state; rooftop stacking is experimental and opt-in).
- The same stale claim was corrected in `README.md`, `core-concepts/regimes.md` and
  `coverage/us-saas-taxability.md` (which asserted the shipped rate source refuses
  `US:*:digital_service` — true only when the dataset is disabled).
- **Schema version was one behind.** `coverage/us-tax-dataset.md` and the
  `config/tax.php` block both said the dataset is schemaVersion 3; the loader
  (`UsTaxData\UsTaxDataset`) reads schemaVersion 4.
- `coverage/_index.md` did not link `us-tax-dataset.md` at all, leaving the
  authoritative US source page unreachable from the coverage index; the SaaS and
  nexus pages are now labelled as the fallbacks they became.

### Notes

- **Documentation only.** No runtime behaviour changed — the sole non-docs edit is a
  comment in `config/tax.php`. Full suite green.

## [0.3.0] - 2026-08-04

### Added

- **Curated rate & baseline notes from the US dataset.** `UsTaxDataset` now exposes
  `rateNote(string $state): ?string` and `baselineNote(string $state): ?string` — the
  human-readable caveats the compiled us-tax-data dataset carries per state (what the
  rate records include, what is not modeled, a point-in-time snapshot, or why a
  "no general sales tax" state still levies a gross-receipts tax). Previously only the
  intrastate **sourcing** note was reachable; the rate and baseline notes (the
  "see state note" / "see baseline note" caveats in the dataset's coverage matrix) were
  loaded but not surfaced.
- `UsTaxDataset` is now bound in the container (nullable, deny-by-default), so a consumer
  can resolve it and read this dataset metadata directly. It resolves to `null` when the
  US dataset is disabled or unconfigured, exactly as the rate/taxability/nexus/sourcing
  adapters already fall back.

### Security

- Bumped `guzzlehttp/guzzle` to `7.15.2` (CVE-2026-69245 noncanonical cookie domain keeps
  subdomain scope; CVE-2026-69246 noncanonical host bypasses host-based checks).

### Notes

- **Backward compatible.** The new accessors and binding are additive; every existing
  query and assessment is unchanged and the full suite stays green.

## [0.2.1] - 2026-08-03

### Changed

- Ship the MIT licence text with the package.

## [0.2.0] - 2026-07-20

### Added

- **First-class buyer tax exemptions.** A `TaxQuery` now accepts an optional
  `exemption` — a `TaxExemption` value object carrying the legal basis
  (`ExemptionType`: resale / nonprofit / government / other), an opaque certificate
  reference, the jurisdiction(s) covered (country- and subdivision-level), and an
  optional validity window.
- The calculator applies the exemption **deny-by-default over the regime's
  verdict**: a valid exemption that covers the taxed jurisdiction rewrites a
  would-be `Standard` line to a native `Exempt` assessment (net kept, tax 0,
  gross = net), with the driving `TaxExemption` recorded on
  `TaxAssessment::$exemption` and named in `reason`. Reverse-charge,
  not-registered, zero-rated and already-exempt outcomes are left untouched. An
  exemption for a different jurisdiction, or an expired/not-yet-valid one, does not
  exempt.
- Coverage is matched at the taxing jurisdiction's granularity: a sub-federal place
  (US state, CA province) requires a matching subdivision; a national place
  requires a matching country.
- Dogfooded testing surface: `InteractsWithTax::taxExemption()` builds an exemption
  from ISO code strings and `assertExempt()` asserts an exempt outcome.
- Docs: `core-concepts/exemptions.md`, plus updates to the architecture, regimes
  and testing references.

### Notes

- **Backward compatible.** `exemption` defaults to `null`; every existing query and
  assessment is unchanged, and the full pre-existing suite stays green.
