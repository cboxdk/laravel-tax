# Changelog

All notable changes to `cboxdk/laravel-tax` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html) (`0.x`:
minor bumps may carry additive features; patches are fixes and docs).

## [0.11.0] - 2026-08-15

### Added

- **Marketplace facilitator.** Every US state with a sales tax makes a qualifying
  marketplace liable to collect on its third-party sellers' supplies. Pass
  `marketplaceFacilitated` and the engine returns `MarketplaceFacilitated` — nothing
  charged, kept apart from `Exempt` and `NotRegistered` because all three are a zero
  and mean opposite things on a return. Checked on the supply's date, so a backdated
  Missouri sale from 2022 is still the seller's.
- **Sales tax holidays.** Fifteen states' 2026 back-to-school windows, applied from
  the supply's date. The cap is all-or-nothing and its inclusivity is per statute —
  Texas exempts clothing "less than $100", Florida "$100 or less".
- **`ProductCatalogue`.** Map your SKUs to tax classes once and send `itemCode` per
  line. An unmapped SKU is reported (`RateLimit::ItemUnmapped`) rather than quietly
  priced from the fallback.
- **`RateLimit`.** What limited an answer and the one step that closes it.
  `Confidence` says how much to trust a figure; this says what to do about it.
- **`RateProvenance`.** Which published data answered a rate — dataset, version,
  the dated window, the section hash — so an assessment can be found again after
  that data is corrected.
- **`LocalAuthorityResolver`.** The seam a state portal or commercial adapter plugs
  into for the states the shipped dataset cannot resolve below the state line.
- **CN/CPA commodity codes.** `EuTaxDatasetRateSource` implements
  `CommodityRateSource`: a heading the publisher could not settle is answered by the
  supply's own classification code, which the source scopes each competing rate to.
- **County resolution for Florida, Pennsylvania, Hawaii and Virginia.** No boundary
  file and no opt-in — the county is the only authority that can tax there.
  Address-exact coverage goes from 26 states to 30.

### Fixed

- The document plane lost `postalCode`, `marketplaceFacilitated` and `itemCode` in
  `TaxOrder::queryFor()`, so a Tenerife invoice was charged mainland Spanish VAT and
  a marketplace order double-charged. A test now reflects over `TaxQuery`'s
  constructor and fails if any parameter stops being passed.
- `assessLine()` skipped classification, so the product catalogue was unreachable
  from a document and an unmapped SKU raised no flag there.
- The boundary index — the file deciding *which* authorities apply — reached the
  engine without being verified against its manifest.


### Fixed — correctness and fail-safe direction

These came out of a platform review and each one is a case where the engine
answered confidently instead of refusing. **Several are behaviour changes**: code
that previously got a number back may now get an exception. That is the point —
every one of them was returning the wrong number.

- **UK VAT IDs were validated fail-OPEN.** `HmrcVatValidator` treated *any* 2xx
  JSON response as a conclusive VALID — an empty object, an error envelope, a
  captive portal serving JSON. That permitted reverse charge, so a UK B2B supply
  was zero-rated against a number nobody had checked, with an audit trail claiming
  otherwise. A conclusive valid now requires HMRC to echo the requested number in
  `target.vatNumber` alongside a registered `target.name`; anything less is
  inconclusive and tax is charged.
- **Undetermined US taxability silently became taxable.** `StaticProductTaxability`
  threw only for `DigitalService` and returned `true` for everything else, so **84
  of the 95 (state, category) pairs the dataset deliberately leaves undetermined**
  — because its sources disagree — were charged full tax. Every US category except
  `Standard` now refuses. `Standard` keeps its default because general merchandise
  is taxable in every sales-tax state; that rule states the law rather than
  guessing at it.
- **Conditional taxability charged the full rate.** Massachusetts exempts clothing
  below $175, New York below $110, Rhode Island below $250. The dataset carries all
  three; `TaxabilityTreatment::isTaxable()` collapsed `Conditional` to `true` and
  the conditions were discarded, over-charging every exempt garment in those states.
  A conditional rule now refuses until the seam can carry the line amount.
- **Alaska was charged at an affirmative 0%.** Its baseline is `stateRate: 0` with
  `noSalesTax: false` — no *state* tax, but boroughs and cities levy their own
  (Juneau 5%, Wrangell 7%, and no statutory cap on how high a borough may go).
  That produced a real 0% `Standard` assessment: a confident "no tax due" on a
  supply that is taxed. A zero state share under real local taxes now refuses.
- **Commodity codes were dropped by the default wiring.** `ResolvesRates` decides
  whether to pass the code by testing the *outermost* source, and the provider
  composes a `ChainTaxRateSource` whenever the US dataset is enabled — the default.
  Both `ChainTaxRateSource` and `CachingTaxRateSource` advertised only
  `TaxRateSource`, so every commodity-aware source beneath them was unreachable
  through the calculator while their own tests passed. Both now implement
  `CommodityRateSource` and forward.
- **The rate cache was rooftop-blind.** `CachingTaxRateSource`'s key carried
  country/subdivision and category only, so every rooftop address in a state
  collapsed onto one entry — a Los Angeles rate served for a San Francisco address.
  The key now covers the locality, the commodity code, and a namespace for the
  composition. Not exploitable in the shipped wiring, which only wraps a
  country-level source; a trap for anyone composing it as documented.
- **A sourced rate outside 0–100% was accepted.** `new TaxRate('-25')` produced
  −25.00 in tax. A malformed feed, or a fraction/percent unit mismatch after a
  schema change, silently credited or over-collected. `TaxRate` now raises
  `ImplausibleTaxRate`.

### Fixed — a dependency was fabricating subdivision codes

`cboxdk/laravel-geo` read the addressing library's `getCode()` — the POSTAL/display
abbreviation — where it meant `getId()`, the ISO 3166-2 code. It failed in two
directions at once. Most values were rejected by the format check and silently
skipped, so `subdivisions('ES')` returned **0 of Spain's 52**. But a display name
that happened to be short and alphanumeric slipped THROUGH as a plausible
invention: Japan's "Mie" became `JP-MIE` and India's "Goa" became `IN-GOA`. Neither
is an ISO code — they are `JP-24` and `IN-GA` — and nothing anywhere said so.

Fixed in laravel-geo with tests over ES/MX/JP/IN, plus a property test that every
code the repository lists is one `find()` resolves. Canada, the only country the
old test covered, is the worst possible choice: its postal and ISO codes are
identical, so reading the wrong field passed anyway.

### Fixed — a reduced category is a reduced STATE share

Missouri's 1.225% grocery rate and Tennessee's 4% are **state shares**; both states'
own guidance says local sales taxes still apply to food. The engine returned the
reduced figure as the whole rate, so a Missouri grocery basket was charged 1.225%
against a real rate that reaches 8%+.

It now stacks — and stacks the right local number. The dataset carries
`foodDrugRate` per locality alongside the general rate precisely because they
differ: a Tennessee city may levy 2.75% generally and 2.25% on food, and some
exempt food locally altogether. Reading the general rate there would have replaced
one wrong answer with another.

Without a rooftop locality the reduced share is returned at `Confidence::Derived`
rather than `Authoritative` — it is the same partial answer the general state rate
is, and it now says so.

Verified against the published dataset: 2,550 Missouri and 483 Tennessee localities
carry a food rate. **Utah does not** — all 210 carry zero, so the engine returns
1.75% where Utah's real grocery rate is a flat 3.0%. That is a gap in the DATA, not
the code, and it is tracked against `us-tax-data`.

### Security — the dataset is verified, not just fetched

The publisher's `manifest.json` carries a sha256 per section and the schema version
the files were built to, and the ETL's own docs tell consumers to check both.
Neither was checked. Two holes, no alarm on either:

- a schemaVersion bump that re-scaled `stateRate` from a fraction to a percentage
  would have been multiplied by 100 again here — **725% tax at `Authoritative`
  confidence**, reaching every deployment on the next cache expiry with nobody
  releasing anything;
- the default `location` is a **mutable branch head on a third-party host**, so one
  bad push lands everywhere within one TTL.

Verification is now required over http(s) and optional for a local directory. That
line is deliberate: over the network you did not choose the bytes; on your own disk
you did. A remote source with no manifest, a schema this reader was not written
for, or a section whose bytes do not match now **deny**.

### Fixed — EU place of supply: Art. 45 is the rule, destination is the carve-out

The engine taxed every B2C supply at the customer's location. That is right for
goods (Art. 33(a)) and for telecoms/broadcasting/electronic services (Art. 58), and
wrong for services in general: **Art. 45 places a service to a non-taxable person
where the SUPPLIER is established.**

A German consultancy invoicing a French consumer €100 was charged FR 20%. It owes
DE 19%, and owes no OSS obligation for that supply at all.

`TaxCategory::placeOfSupplyRule()` now classifies each class, and `EuVatRegime`
applies it. Goods and electronically-supplied services are unchanged. Deliberately
limited to the intra-EU case — a supplier established outside the Community
supplying general services to an EU consumer stays on destination, because Art. 45
would put that supply outside EU VAT entirely while Art. 59a lets a Member State
pull it back on effective-use-and-enjoyment grounds, which is a per-state option
this engine does not model.

`ServicesRepair` and `ServicesPersonalCare` are classified `WhereProvided` (Art. 54,
taxed where the work is done). The engine does not carry a performance location, so
it still uses the customer's location as a proxy — for a consumer having a device
repaired the two nearly always coincide, and the classification records that it is
a proxy rather than the rule.

**Art. 59c relief is now gated to the supplies it covers.** It disapplies Art. 33(a)
and Art. 58 and only those — it is relief for intra-Community distance sales of
goods and for TBE services, not a general small-seller exemption. Granting it to,
say, admission to an event charged origin VAT on a supply taxed where the event is.

### Breaking — the disabled-dataset path

`tax.us_tax_data.enabled=false` is now a narrow escape hatch rather than an
equivalent mode. Rates still fall back to the static snapshot and nexus to the
shipped table, but taxability does not: only `Standard` and your own overrides
resolve, and every other US category raises `UnresolvedProductTaxability`. The docs
previously implied the static tables covered taxability; they never did beyond
`digital_service`, and the old behaviour was to answer `true` regardless.

Mirror the dataset to a local directory and point `location` at it rather than
disabling it. Fabricating 25 categories × 51 states of determinations we have no
source for is the one thing this package will not do.

### Changed — honesty

- **The dataset's licence is now disclosed where it is used.** This package is MIT;
  `cboxdk/us-tax-dataset`, which it fetches **by default**, is PolyForm Internal
  Use 1.0.0. Computing tax on your own sales is the intended use and fine; offering
  a rate lookup, an API, or a product feature that gives your customers the rates
  is distribution and needs a separate licence. The optional, disabled-by-default
  EU feed documented its MIT licence four times while the enabled-by-default US one
  documented nothing — that asymmetry is what made this worth fixing first.
- **"Primary-sourced" was half true, and the false half is the number most callers
  get.** The local rate records genuinely are primary — the SST Governing Board's
  own files for 24 states, plus each state's revenue department. The **51
  state-level rates are not**: they come from a single Tax Foundation compilation,
  which is the same footing the EU feed is on and is now described the same way.
  That is the figure returned in the 16 states with no rooftop path.
- The SaaS taxability map's two sources are now labelled as what they are: vendor
  guidance, not tax authorities, with no statutory citations and no obligation to
  stay current. Requiring both to agree is the only cross-check there is, which is
  why a disagreement ships as a refusal.
- The rooftop coverage gap is **sixteen** states, not the "six" the docs claimed
  before listing eight. Recomputed against the published dataset: 37 states levy
  local tax, 26 have a rooftop path. AL·AZ·CO·FL·HI·ID·IL·LA·MO·MS·NY·PA·SC·TX·VA
  resolve to the state share at `Confidence::Derived`; AK now refuses. **NY and IL
  were undisclosed and both matter for SaaS.**
- The README no longer claims the US regime does "sourcing logic". `SourcingRules`
  is bound and backed by a dataset section, but no regime consults it — it is data
  for a host to consume, and it now says so.
- The README's Art. 45 claim is now true rather than removed — see the place-of-
  supply fix above. It briefly said Art. 44/58 only, which was the honest
  description of the code at that moment.

### Added — fixed charges that are not a percentage of anything

`FlatCharge`, the `FlatChargeSource` seam, and `TaxAssessment::$charges` /
`payable()`.

`TaxRate` is a percentage and refuses to be anything else — the constructor
rejects a value outside 0–100 and components must sum to it exactly. Those
invariants are right, and they made a real class of charge inexpressible:
Colorado's Retail Delivery Fee is **$0.31 per order** from 1 July 2026 and
Minnesota's is $0.50. A caller could only fake one as a rate derived from that
order's total, which changes per order and fails the reconciliation check anyway.

`gross` deliberately stays `net + tax` — the invariant holds throughout the engine
and several things depend on it — so a charge sits beside it and `payable()` is
what the buyer owes once billed-on charges are added. A charge marked
`passedToBuyer: false` is one the seller must absorb, and it is excluded from what
the buyer pays; reporting one without saying so would put it on a customer's
invoice.

The source is handed the ASSESSMENT as well as the query, because applicability
turns on the outcome: Colorado's fee is due on a delivery containing taxable goods.

**The package ships no charges**, for the same reason it ships no reduced-rate
bands: no authoritative compilation of these sits behind it, and inventing one
would be worse than the gap. What was missing was not the data but the ability to
express it.

Note what is deliberately NOT here: a non-pass-through flag on rate-based tax.
Gross-receipts taxes work that way, but nothing in the dataset carries them, and a
field nothing populates is the mistake `SourcingRules` already demonstrated.
`FlatCharge::$passedToBuyer` covers the case where we have something to attach it
to.

### Added — two dates, because one cannot do both jobs

`TaxQuery::$reportedOn` decides which RETURN PERIOD a supply falls into;
`suppliedAt` still decides the rate. They are usually the same date and are not
always: goods supplied on 30 December and invoiced on 3 January are rated at
December's rate while national tax-point rules may put them in either period.
Conflating them silently misfiles every invoice that straddles a period end.

`ReturnAggregator::aggregate()` now takes an optional `ReturnPeriod`, and
`ReturnPeriod::quarter()`, `::month()` and `::year()` build the windows an
authority actually files on — with **inclusive** bounds, because a quarter that
ends on 31 December has to contain the supplies made on 31 December.

An assessment carrying no reporting date is EXCLUDED from a period rather than
assumed into it: a supply that cannot say which period it belongs to must not land
on a return someone signs. Aggregating without a period keeps the previous
behaviour.

### Added — where a supply came FROM

`SupplyRoute` on `TaxQuery` and `TaxOrder` carries `shipFrom`, `orderAcceptance`
and `billTo`. `place` remains the destination; these are the roles it never had.

**This is what finally connects `SourcingRules`.** It shipped bound, backed by a
whole dataset section, and read by nothing — not from neglect, but because nine US
states source an in-state sale at the SELLER's location and `TaxQuery` had no field
to source from. A Houston seller shipping to an unincorporated Harris County
address was charged the buyer's 6.25% where Texas wants the seller's 8.25%: a 2%
error in the seller's own home state, the one they are most likely to be audited
in.

Three things must hold before origin sourcing applies, and any missing one falls
back to destination rather than guessing: a bound `SourcingRules` that knows the
state's rule, a rule of `Origin` (not `Mixed` — California splits by jurisdiction
layer in ways one place cannot express), and a supplied origin in the SAME state,
because interstate is destination-sourced everywhere without exception.

Supplying no route keeps the previous behaviour exactly.

### Added — invoice mentions

`TaxAssessment::$mentions` carries the legal statements the **invoice** must
bear, as `{code, text, reference}` rather than prose.

This is not formatting. Art. 226(11a) of the VAT Directive requires the words
**"Reverse charge"** on the invoice, and the CJEU held in *Luxury Trust Automobil*
(C-247/21) that a missing mention **cannot be corrected retroactively** — the
supply stays defective. Until now the only output was `reason`, an English
explanation written for an audit trail, and a caller printing that produced an
invoice that could never be fixed.

The EU regime emits the mandatory wording with its citation; the shared
destination regime emits nothing unless a regime supplies its own, because a
Directive citation on a UK or Norwegian invoice would be a defect a reader would
trust. A certificate-driven exemption names the certificate it rests on.

### Added — documents

- **A document is now a first-class thing.** `TaxOrder` carries the context every
  line shares plus `SupplyLine[]`; `OrderTaxCalculator::assessOrder()` returns an
  `OrderAssessment` with each line's verdict tied to the id the caller sent. A real
  SaaS invoice is a subscription, metered usage and one-off services — three
  categories on one document, settled once. Three separate `assess()` calls round
  three times and produce three assessments nothing ties together.
- **The order plane adds no tax logic.** `TaxOrder::queryFor()` is the single place
  a line becomes a `TaxQuery`, so every regime, gate and refusal applies exactly as
  it does for one supply, and a document cannot reach an outcome a single supply
  could not. A line that refuses fails the whole document: half a tax-assessed
  invoice is not a useful artefact.
- **Totals are summed from the rounded lines, never recomputed.** Rounding the sum
  instead produces a total that does not equal the invoice rows beneath it.
- `SupplyLine::$pricing` overrides the document's, because a subscription quoted
  VAT-inclusive beside usage quoted exclusive is an ordinary invoice.
- `OrderAssessment::taxByAuthority()` rolls a document up per taxing authority for
  remittance, and returns **null** rather than a partial roll-up when any taxed
  line could not be decomposed — a partial one looks like the document's split
  while silently omitting lines, and looks reasonable doing it.
- A `TaxOrder` must have at least one line, one currency, and unique non-empty line
  ids. The id is how tax gets back onto an invoice row; two lines sharing one means
  the totals count both while a lookup finds only the first.
- Deliberately absent: `quantity` and per-line `discount`. The mature APIs carry
  quantity because they support quantity-based taxes (per-litre duty, per-unit
  fees); this engine has none, so it would be a field nothing reads. Discount
  allocation is commercial logic — which lines a promotion touches, how a
  whole-order discount splits, what rounds where — and an engine that took it would
  be making those calls silently, with money.

### Added

- **Per-authority rate breakdown.** A stacked US rooftop rate now keeps the
  authorities it was summed from (`TaxRate::$components`), and an assessment
  carries a `TaxBreakdown` splitting its tax across them — the state share, each
  county/city/special-district share — so a seller can remit per jurisdiction
  instead of only knowing the combined figure.
- The shares are **allocated from the tax actually charged**, not recomputed per
  authority, so `breakdown->total()` equals `assessment->tax` exactly. Applying
  each rate to the net independently rounds every share on its own: on a $1.00
  supply in Kansas City that yields 0.07 + 0.01 + 0.02 = $0.10 against a $0.09
  tax — a cent that was never collected, in a return that no longer reconciles.
  The remainder goes to the largest fractional shares (Hamilton), so list order
  never decides who is owed it and a 0% authority never receives one.
- `TaxRate` enforces that components sum to the rate
  (`RateComponentsDoNotReconcile`). A slightly-wrong split is worse than none: it
  has the shape of an authoritative one, so it gets remitted on and the shortfall
  surfaces at audit. A source that cannot decompose a rate emits none, and a null
  breakdown means **unknown**, never "one authority takes it all".
- `InteractsWithTax::assertBreakdownReconciles()` for consumers, dogfooded by this
  package's own suite.

### Fixed

- A **combined-basis** state (California) whose boundary index answers "no local
  authority applies here" no longer reports the bare state share as an
  authoritative all-in rooftop rate. A combined record *is* the all-in rate, so
  the absence of one leaves nothing all-in to report; it now falls back to the
  state rate at `Derived` confidence. Component-basis states are unaffected —
  Indiana levies no local tax, and its state share genuinely is the whole rate.

### Notes

Every line's `taxableAmount` is the supply's full net, because the engine applies
one taxable base across the stack. That is correct for the states modelled today
but would not be for a state that exempts a category at state level while its
localities still tax it (Illinois and Missouri do this for groceries). That needs
a per-level taxability seam, so a per-level base is deliberately not claimed yet.

## [0.9.1] - 2026-08-06

### Added

- **A watch on the authority pages behind the shipped rates.** `bin/watch-rates.php`
  hashes the readable text of each authority's rate page and compares it to a
  committed baseline; a monthly workflow opens an issue when one moves. **13
  jurisdictions** are watched (GB, IE, NO, CH, NZ, SG, JP, MY, TR, SA, AE, PH, TH),
  each URL verified to serve real content first.
- It never parses a rate out of a page. A changed page means *verify*, and a human
  updates the dated window — reading a percentage out of prose would be a guess that
  fails silently, which is the failure this exists to catch.

### Notes

Two things had to be got right or the alarm would be worthless:

- **Noise.** The UAE's page carries a visitor counter, so two fetches a second apart
  differed and every run would have reported a change. Bare integers of six digits or
  more are stripped — safely past any tax rate, since the longest shipped is
  `14.975` — so counters and build ids go while rates stay.
- **Transients.** Malaysia's site serves occasional variants that no normalisation
  catches. A page that looks changed is fetched a second time and only reported if
  the change confirms; an unconfirmed one keeps its old baseline rather than
  silently adopting the variant.

An alarm that cries wolf trains you to ignore it, and so does one that is
permanently red — India's page serves cURL but rejects PHP's stream wrapper, so it
was dropped back to the unwatched set rather than shipped as a monthly failure.
Australia and Canada block automated fetching outright.

## [0.9.0] - 2026-08-06

### Fixed

- **Historical queries returned today's rate.** `TaxRateSource::rateFor()` has always
  accepted an `$at`, and `StaticTaxRateSource` accepted it and never read it. Asking
  for Türkiye's rate on 2023-01-01 answered 20% when 18% applied until 10 July that
  year — so reissuing an old invoice, or raising a credit note against one, priced it
  at the wrong rate with no indication anything was off.

### Changed

- **Rates are dated windows, not a flat map.** They move from a hardcoded array into
  `resources/rates.json`, one or more `{from, to, rate}` windows per jurisdiction with
  the authority named. A query resolves the window covering its date.
- Prior windows are carried where this package's own coverage docs already record a
  dated, primary-source-verified change: **Türkiye** 18% → 20% (10 Jul 2023),
  **Saudi Arabia** 5% → 15% (Jul 2020), **Bahrain** 5% → 10% (Jan 2022) and
  **Malaysia** 6% → 8% (1 Mar 2024). Absence of a prior window is not a claim that a
  rate never moved — only that no dated change is recorded for it, which the overlay
  says out loud.
- `StaticTaxRateSource::defaults()` still returns a flat map, now derived from the
  windows in effect today, and a caller-supplied map still works — it becomes one
  open window per jurisdiction.

### Notes

- Groundwork rather than a feature: the 50 shipped rates still have no live source,
  because none exists. Verified during planning that the OECD's SDMX API carries no
  VAT/GST *rate* dataflow — across all 4,603 dataflows the only matches are national
  accounts. Dating the rates is what makes the next step possible: an overlay that
  can express a change is a thing a monitor can update.

## [0.8.2] - 2026-08-05

### Fixed

- **`ArcGisRateSource` shipped undocumented.** v0.8.0 added a public rate source and
  bound it automatically, but `extension-points/rate-sources.md` — the page someone
  reads to learn which sources exist — never mentioned it. It now has its own
  section: the two services, what a point returns, the verified figures, and the
  three things it deliberately does not do (no category rates, no stacking, needs
  coordinates).
- **`extension-points/geocoding.md` described only half the behaviour.** It said the
  adapter attaches a ZIP+4, which stopped being the whole truth in v0.8.0: a
  `Jurisdiction` carries one locality, so the adapter attaches a **point** for
  California and New Mexico and a **ZIP+4** for the Streamlined states. The page now
  shows which states get which, and why.

## [0.8.1] - 2026-08-05

### Fixed

- **Docs still said the boundary indexes were unpublished.** They shipped earlier the
  same day — all 24 Streamlined states, 5.4 MB gzipped, refreshed quarterly and
  verifiable against `boundaries/manifest.json` — but `README.md`,
  `coverage/supported.md` and `coverage/us-tax-dataset.md` all still told readers the
  lookup 404s and the state rate applies. Corrected to what is true: rooftop is live
  for **26 states**, and the honest remaining gap is the seven states plus Texas that
  publish nothing usable.

## [0.8.0] - 2026-08-05

### Added

- **`ArcGisRateSource` — rooftop for California and New Mexico, by polygon.** Neither
  state publishes a boundary file, but both publish an official, unauthenticated
  ArcGIS service of polygons carrying the jurisdiction and its rate. A point query
  resolves an address directly, which is **finer** than a ZIP+4: real geography
  rather than a postal proxy for it.
- Verified against both services: Los Angeles City Hall **9.75%**, San Francisco
  **8.625%**, Albuquerque **7.625%**, Santa Fe **8.1875%**. California publishes the
  rate as a fraction and New Mexico as a percentage; both are normalised.
- New Mexico's is the service the compiled dataset **already reads** for rates — it
  had simply never been queried with geometry.
- Because a jurisdiction carries exactly one locality, `GeocodioGeocoder` now emits a
  **point** (`latlng`) for those two states and a **ZIP+4** (`zip9`) everywhere else.
  The list of polygon states lives in one place, with the source that uses it.

### Notes

- Live query per point, cached — the posture of the TEDB source rather than the
  shipped boundary indexes. Misses are cached too, so an address in the sea does not
  re-query on every assessment.
- Rooftop now reaches **26 states**. Six have no path from anything published:
  Arizona, Colorado, Louisiana, Missouri, Illinois, Alabama, Alaska — and Texas,
  which does produce an SST-formatted address dataset but behind an audited account
  portal labelling the data sensitive, so no publicly redistributable index can be
  derived from it.

## [0.7.1] - 2026-08-05

### Notes

- **Documented why the package requires Laravel 13 only.** A library should install
  on the current *and* previous major, and this one does not. Investigated properly:
  `brick/money ^0.14` — the exact-money library every amount and rate calculation
  runs through — requires `brick/math ~0.15` or newer, while `laravel/framework`
  12.64 caps it at `^0.11|^0.12|^0.13|^0.14`. The ranges are disjoint. Supporting
  Laravel 12 would mean pinning `brick/money` back to `^0.11`, three minors and a
  different rounding surface, in a tax engine where the money library's behaviour
  *is* the calculation.
- Nothing in this package's own graph causes it: `illuminate/support`,
  `illuminate/contracts` and `illuminate/http` never require `brick/math`. The cap
  lives in the full framework package, and only from 12.64 onward. `requirements.md`
  records this so the gap reads as a decision rather than an oversight, and the
  constraint widens the moment a Laravel 12 patch relaxes it.

## [0.7.0] - 2026-08-05

### Added

- **Commodity codes close the splits a category cannot.** `TaxQuery` takes an
  optional `commodityCode` — a **CN code** for goods or a **CPA code** for services.
  This is not an invented taxonomy: TEDB scopes its own rates by exactly these, and
  92% of its reduced and exempt entries carry them, most at full 8-digit depth. The
  Commission publishes the Combined Nomenclature free and machine-readable.
- `Contracts\CommodityRateSource` — a **separate** contract, not a fourth parameter
  on `TaxRateSource`, so a source that cannot use codes keeps working untouched.
  `TedbSoapRateSource` implements it; `ChainTaxRateSource` and
  `CachingTaxRateSource` are unaffected.
- Measured across the nineteen splits left open in v0.6.0, disjoint CN scopes
  resolve **seven outright** — AT/BE/HU groceries, IT medical devices, IT/PL
  pharmaceuticals, PL books — and narrow the rest to a handful of overlapping codes.
  Verified live: Polish beef `0201` resolves 5% where the category alone gives 23%;
  Hungarian yoghurt `0403` gives 5% against 27%; Austrian fresh fish `0302` gives
  10% against 20%.

Four rules keep it safe:

- The code **refines, never restricts** — absent or unrecognised, the category alone
  decides exactly as before.
- A code TEDB itself lists at **several rates** within a category is dropped; it is
  no more decisive than the category.
- Spacing is irrelevant (`0504 00 00`, `05040000`, `0504.00.00`).
- A code is tried at successively shallower depths (8 → 6 → 4 → 2), since a state may
  scope a rate to a whole heading rather than one subheading.

### Notes

- Additive throughout. `commodityCode` defaults to null, the `TaxRateSource`
  contract is unchanged, and every regime routes the code through a shared trait so
  the five of them cannot drift.

## [0.6.0] - 2026-08-05

### Added

- **Determinations for the EU categories TEDB splits.** Where TEDB carries a mapped
  category at several rates, the band was refused and the standard rate applied —
  Irish books were charged 23% where the true rate is 0%. Thirteen of those splits
  are only apparent: the competing rate belongs to a *different product class*, and
  TEDB's own scope note says which. Ireland's 9% "foodstuffs" rate is restaurant,
  canteen and takeaway food; its 13.5% "medical equipment" rate is *repairs* to
  equipment; France's 10% pharmaceutical rate is the non-reimbursed one. Those are
  now resolved, each with the note it rests on recorded beside it.
- Covers `grocery`, `books`, `newspapers`, `magazines`, `medical_devices` and
  `prescription_drugs` in IE; `prescription_drugs` in FR, HR, BE and EL; and
  `books`/`newspapers`/`magazines` in BE.

Two properties keep them honest:

- A determination is consulted **only when TEDB is ambiguous**. A member state
  reporting one rate is never overridden.
- It is applied only while the rate it names is **still one TEDB returns**. If a
  state changes its split, the determination stops matching and the band is refused
  rather than shipped stale — it self-invalidates instead of quietly going wrong.

### Notes

- **What stays open, and why it is not curatable.** Nineteen splits remain, because
  the category genuinely spans several rates by product type: Hungary rates meat,
  fish, milk and eggs at 5% and dairy desserts, flavoured milk and cereals at 18% —
  both are groceries. `grocery` in AT/BE/EL/HU/IT/PL/PT/SK, `prepared_food` in SK,
  `medical_devices` in CY/EL/IT, `prescription_drugs` in IT/MT/PL, `newspapers` in
  HR, `books` and `magazines` in PL. They resolve to the standard rate. Closing them
  needs a finer product category — CN-code granularity — not more curation.

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
