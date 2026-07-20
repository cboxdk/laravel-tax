# Changelog

All notable changes to `cboxdk/laravel-tax` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html) (`0.x`:
minor bumps may carry additive features; patches are fixes and docs).

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
