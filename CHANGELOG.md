# Changelog

All notable changes to `cboxdk/laravel-tax` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html) (`0.x`:
minor bumps may carry additive features; patches are fixes and docs).

## [0.5.4] - 2026-08-05

### Fixed

- **A transient geocoding failure aborted the assessment.** Geocodio answers
  `403 Invalid API key` intermittently on a valid key — observed twice in roughly
  ten calls. Before rooftop that cost a state-level rate; with rooftop enabled an
  unresolved address raises `JurisdictionNotResolved` and the whole assessment
  fails. `GeocodioGeocoder` now retries once (250 ms apart) before believing a
  failure. A genuinely invalid key costs one extra request; deny-by-default is
  unchanged once the retry is exhausted.

## [0.5.3] - 2026-08-05

### Fixed

- **"No local authority applies" was reported as a fallback.** Where the boundary
  index positively answers that no local authority covers an address — as it does
  everywhere in Indiana, Kentucky, Michigan, New Jersey and Rhode Island, which levy
  no local sales tax — the resulting rate equals the state share but is a *complete*
  all-in answer. It was returned at `Confidence::Derived`, the same signal as "we
  only know the state share and locals may be missing". It is now `Authoritative`,
  and an index that carries nothing for the address stays `Derived`.

### Notes

- **Rooftop is live.** The indexes are published, and the chain resolves end to end
  against the public mirror: Kansas City `9.125%`, Seattle `10.55%`, Indianapolis
  `7%` — the first two authoritative sums over several authorities, the third an
  authoritative statement that none apply.

## [0.5.2] - 2026-08-05

### Changed

- **Boundary indexes are read gzipped.** `boundaries/US-XX.json.gz` is tried first
  and inflated, falling back to the plain file where a local build wrote one. The
  indexes are published compressed — 5.4 MB across the 24 SST states instead of
  20 MB — because they are rewritten every quarter and the uncompressed form would
  dominate the mirror's history.
- Adds **`ext-zlib`** to `require`, stated in `requirements.md`.

## [0.5.1] - 2026-08-05

### Fixed

- **A partial rooftop match under-charged.** Where the boundary index named an
  authority the rate records do not carry, the sum was taken over the rest and still
  labelled `Confidence::Authoritative` — short by that authority's share, and
  confident about it. Every resolved code must now produce a record; a partial match
  falls back to the state rate at `Confidence::Derived`. Found while wiring the real
  Kansas index, where `66101-3413` resolves to Wyandotte County **and** Edwardsville.

### Changed

- Reads the boundary index's compact encoding: a `sets` table of distinct authority
  combinations, `zip` spans as `[from, to, setIndex]`, and `ranges` as
  `[zipFrom, zipTo, from, to, setIndex]` for rows spanning several ZIP5s. The
  dictionary encoding and span merging cut the published indexes from 60 MB to
  20 MB raw (5.4 MB gzipped) across all 24 SST states.
- `ranges` matter for correctness, not just size: Indiana, Kentucky, Michigan, New
  Jersey and Rhode Island state their whole territory in one or two ZIP-spanning
  rows carrying no local authorities — the correct answer for states that levy none.

## [0.5.0] - 2026-08-05

### Changed

- **`UsTaxDatasetRateSource` now sums every applicable local record**, where it
  previously stacked exactly one. That was right where only a city record applies
  at an address (Seattle) and 100 bp low where a county applies alongside it
  (Kansas City): 6.5% state + 1.0% county + 1.625% city = the 9.125% Kansas City
  levies. Which records apply comes from the dataset's boundary index, so there is
  no per-state rule — the same code path produces both answers.
- **`GeocodioGeocoder` attaches a ZIP+4 locality** (scheme `zip9`, e.g.
  `66101-3064`) from Geocodio's `zip4` append, replacing the county FIPS it took
  from the `census` append. The county FIPS could never resolve: it names one
  authority where several may apply, and Geocodio's is state-prefixed where the
  dataset's codes are not. A ZIP+4 is a postal key that the boundary index expands
  into the authorities that actually apply.
- Geocodio returns `zip9` as a list; where an address spans several add-ons no
  locality is attached rather than one being picked. ZIP5 alone is never used — 54%
  of Washington's ZIP5s and 91% of Kansas' span more than one jurisdiction set.

### Added

- `UsTaxDataset::localJurisdictions()` reads a state's `boundaries/US-XX.json`
  index lazily and cached, alongside the existing sections.

### Notes

- **The boundary indexes are not published yet.** `us-tax-data` compiles them
  (`bin/compile-boundaries.php`, proven for Kansas and Washington) but does not yet
  ship them, so the lookup 404s and the state rate applies — unchanged behaviour in
  practice. This side is complete and tested against the real compiled Kansas index;
  it activates when they land.
- Once shipped, rooftop covers the **24 SST member states**. Texas, California,
  Arizona, Colorado, Louisiana, Missouri, New Mexico, Illinois, Alabama and Alaska
  publish no boundary files at all.
- A caller supplying its own `LocalityCode` in a state's native key shape still
  resolves that single authority, as before.

## [0.4.1] - 2026-08-05

### Fixed

- **`ext-dom` was used but not required.** `TedbSoapRateSource` parses the
  Commission's SOAP responses with `DOMDocument`/`DOMXPath`; `composer.json` did not
  declare the extension, so an install without it would have fatalled at runtime
  instead of failing at install time. Declared, and stated in `requirements.md`.

### Changed

- Docs now present the **live TEDB service as the EU default** rather than a
  footnote: `README.md`, `coverage/supported.md` and `coverage/eu-vat-feed.md`
  point at it, and the `eu-vat-feed` page says plainly that the community
  compilation is a good default while TEDB is the tax authority's own database.
- `TedbRateSource`'s docblock no longer tells operators to "point this at a real
  TEDB export". It now says there is no such file to download and describes what
  the adapter is genuinely for — pinning a snapshot you generated and reviewed —
  pointing at `TedbSoapRateSource` for reading TEDB itself.
- The EU confidence note in `coverage/supported.md` names the real caveat: reduced
  bands resolve for some member states and split across sub-scopes in others, where
  the standard rate applies.

## [0.4.0] - 2026-08-05

### Added

- **`TedbSoapRateSource` — the EU Commission's TEDB, called live.** Enable with
  `tax.tedb.live` (env `TAX_TEDB_LIVE`); no API key and no registration are needed.
  Responses are cached per member state for `tax.tedb.ttl` seconds, so it costs one
  request per country per TTL rather than one per assessment.
- It resolves the **standard rate for all 27 member states**, plus reduced/zero
  bands for `grocery`, `prepared_food`, `books`, `newspapers`, `magazines`,
  `medical_devices` and `prescription_drugs` — **only where TEDB resolves that
  category to a single rate for that country**. Where a state carries a category at
  several rates at once (French pharmaceuticals sit at 2.1%, 5.5% and 10%; Irish
  books are zero-rated in print but 9% electronically), the band is refused and the
  standard rate applies. Over-charging is recoverable; silently applying the wrong
  reduced rate is not.
- Where a member state splits a category itself, its own split wins: Poland's
  separate 8% newspaper rate survives, while Sweden — which files newspapers under
  the broader "books, newspapers and periodicals" heading — resolves from there.
- Verified live against seven member states, and against a captured
  `VatRetrievalService` response committed as a fixture.

### Fixed

- **`TedbRateSource` documented a file that cannot be obtained.** The config and
  docs told operators to "point the config URL at an EU Commission TEDB export".
  The Commission publishes no such export — the SOAP service and the web UI are the
  only ways to get TEDB data — so the adapter could not be used without the operator
  first building an ETL of their own. It remains, now honestly described as the way
  to pin a snapshot **you generate**, with the live source as the documented default.

### Notes

- Additive. Both TEDB paths stay opt-in and the static snapshot remains the
  zero-config default, so no existing behaviour changes.

## [0.3.4] - 2026-08-05

### Changed

- **`GeocodioGeocoder` now targets Geocodio API v2**, the current version. The
  default `baseUrl`, the `tax.geocodio.base_url` config default and the provider's
  fallback all move from `https://api.geocod.io/v1.7` to `https://api.geocod.io/v2`.
- Of v2's breaking changes, two touch this adapter: the response no longer carries a
  top-level `input` key beside `results`, and `address_components.state` is now
  `state_province`. **v2 does not emit the old `state` key at all** (verified against
  the live API), so an adapter reading only `state` would have denied every lookup
  against a v2 URL. Both spellings are now read, so passing a v1.x `baseUrl` keeps
  resolving.
- Two further v2 changes do not affect the adapter: `zip` became `postal_code`
  (never read), and Canadian results echo the full postal code where the FSA matches
  rather than returning the FSA alone. The `census` field append is unchanged.

### Upgrade note

If you published `config/tax.php` before this release it still pins
`https://api.geocod.io/v1.7`. That keeps working, but update it (or set
`GEOCODIO_BASE_URL`) to move to v2.

## [0.3.3] - 2026-08-05

### Fixed

- **The rooftop explanation named the wrong obstacle.** v0.3.2 said a county and a
  city record are indistinguishable because the dataset marks both `level: "local"`,
  so the rate source cannot tell whether they stack. Tracing it to the SST **boundary
  file** showed the mechanism is different: local records are always components of a
  sum, and what varies is *which* records a state assigns to an address — inside
  Seattle no county record applies (state + city = 10.55%), inside Kansas City both
  do (state + county + city = 9.125%). The conclusion is unchanged — a trustworthy
  rooftop rate needs boundary data — but the reason is now stated correctly in
  `coverage/us-tax-dataset.md`, `coverage/supported.md`, `README.md`, the
  `config/tax.php` `rooftop` comment and the `UsTaxDatasetRateSource` /
  `GeocodioGeocoder` docblocks.
- Dataset **v0.4.3** types every SST local record by its taxing authority
  (`county`, `city`, `special_district`) where they were previously all `local`, so
  the claim that they are byte-for-byte indistinguishable no longer holds either.
  The docs now note what `level` does and does not tell a consumer.

### Notes

- **Documentation only.** No runtime behaviour changed; the code edits are docblocks
  and a config comment. Full suite green.

## [0.3.2] - 2026-08-05

### Fixed

- **Rooftop resolution was documented as working; it is inert.** The docs claimed the
  rooftop plumbing was "wired end-to-end, so enabling rooftop resolution is a data
  question, not a code one". Probing Geocodio against the compiled dataset disproved
  it on three counts, now documented in `coverage/us-tax-dataset.md`:
  1. Geocodio returns a **state-prefixed** `county_fips` (`53033`) while the dataset
     keys counties without the prefix (`033`), so the lookup never matches and the
     state rate applies.
  2. Only ~19 states key locals by census FIPS; the rest use their own shapes
     (`2109064` TX, `AJ` AZ, `06:OAKLAND` CA, `048-0002-8` IL, `county:Adams` CO),
     so no single identifier reaches every state.
  3. Whether a county and a city record **stack** differs per state — Kansas City
     is 6.5% + 1.0% county + 1.625% city, while Seattle's place record is already
     the total local rate — and the dataset marks both `level: "local"`, so the rate
     source cannot tell them apart. Stacking one record is right for Washington and
     100 bp low for Kansas.
  `README.md`, `coverage/supported.md`, the `config/tax.php` `rooftop` comment and the
  `GeocodioGeocoder` / `UsTaxDatasetRateSource` docblocks now say this plainly.
- **`extension-points/geocoding.md` claimed "Geocodio's own tax append is not used".**
  Geocodio publishes no sales-tax or taxing-jurisdiction append; the page now
  documents what the `census` append actually returns.
- **`requirements.md` pinned `cboxdk/laravel-geo` at `^0.1`**; `composer.json` requires
  `^0.5`.
- `docs/index.md` omitted the Coverage section from its own table of contents.

### Notes

- **Documentation only.** No runtime behaviour changed; the code edits are docblocks
  and a config comment. Full suite green.

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
