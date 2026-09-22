# Cadastre feedback for the next data sweep

Updated on **18 September 2026**, against release **`2026.09.16-236`** and a
read-only review of cadastre's current schema, rule checks and territory normalizer.
This is a review queue. No upstream records or downloaded artifacts were edited.
The [validation matrix](validation-matrix.md) separates data, adapter and engine
responsibilities. The [consumer-contract proposal](cadastre-consumer-contract.md)
contains the requested input facts, failure semantics and input/output cases.
**Its new field names are proposals, pending agreement with cadastre.**

## 1. Correct New York's remote-seller commencement date

**Verified data error.** Selector: `jurisdiction=us:NY`, `kind=threshold`,
`payload.binds=remote_seller`, `payload.amount=500000.00`.

The record has `effective.from=2019-06-01`, `startIsFloor=false`. Its provenance
uses the commencement of the marketplace-provider amendment. The dedicated
remote-seller memorandum [TSB-M-19(4)S](https://www.tax.ny.gov/pdf/memos/sales/m19-4s.pdf)
gives **21 June 2018** as the retroactive effective date of the $500,000 threshold.
**1 June 2019** belongs to [marketplace providers, TSB-M-19(2.1)S](https://www.tax.ny.gov/pdf/memos/sales/m19-2-1s.pdf).

- Correct the remote-seller record's effective window and provenance together.
- Keep marketplace-provider and remote-seller rules separate, including their
  identity and overlap scope. Cadastre has already identified the missing `binds`
  discriminator in threshold identity; its correction is owned upstream.
- Verify the measuring period, sales basis and both threshold limbs against the
  actor-specific source. Add separate amount and transaction comparison operators:
  NY needs **sales > $500,000 AND transactions > 100**, including equality cases.
  Neither `sales_and_transactions` nor `crossing` supplies those comparisons.
- Add an upstream historical case within 21 June 2018–31 May 2019.

**Correction to this feedback:** `crossing` describes **what crossing triggers**,
for example `must_register` or `platform_becomes_liable`. `consequence` describes
**when** the duty begins. `crossing=unstated` does not mean the comparison operator
is unknown, and must not be replaced with `>` or `>=`. These meanings are explicit
in cadastre's `WhatCrossingDoes` and schema `thresholdPayload`.

`RegisterNexus` did not misuse `crossing`: it reads the figures and `combinator`
for an advisory description, and does not compare seller totals or decide nexus.
Regression tests now keep crossing effects, actor selection and AND independent.
Compound remote-seller thresholds that the advisory API cannot represent refuse.
The package selects the published date faithfully, including returning no threshold
before that published start. **There is no NY date override or other data repair
inside the reader.**

## 2. Represent delivery conditions and coverage explicitly

**Contract/coverage gap.** Selector: `kind=taxable_base`, components
`transport_on_taxable_goods`, `handling_on_taxable_goods` and their exempt-goods
counterparts. A single `included` boolean cannot express conditional treatment.

For Kansas, [Publication 1510](https://www.ksrevenue.gov/pub1510.html) describes
conditions including separate statement and true/reasonable delivery costs.
Publishing only `included=false` loses the conditions needed to decide a real charge.

- Extend the existing schema with executable predicates, required host facts,
  conjunction/disjunction, explicit outcomes for both branches and condition sources.
  Existing rate conditions and compound threshold conditions are different shapes;
  their presence does not make delivery predicates executable today.
- Distinguish transport, handling and other incidental costs, taxable and exempt
  goods, partial exemption, and mixed-basket allocation. Inclusion alone does not
  identify an independent rate for taxable delivery of exempt goods.
- Backfill verified historical windows. KS starts at the 14 September 2026 capture
  floor; the authority describes a change from 1 July 2023. Retain `startIsFloor`
  until historical coverage is verified; the engine must not extrapolate backwards.
- Add cases for true, false and unknown conditions, unsupported predicates, mixed
  baskets and credits. An unknown condition must not become an unconditional answer.

The package reads component rules and requires explicit host confirmation through
`DeliveryCharge::exclusionConditionsMet` for a published exclusion. Unknown facts,
absent date coverage and unsupported independent delivery rates refuse. This is a
bridge, not an interpreter for arbitrary new predicates. The reader now also rejects
unrecognised rule fields, even when a host has confirmed the old exclusion flag,
and refuses a proportional delivery base that a boolean cannot represent.

## 3. Add historical boundary coverage

**Coverage gap.** Installed ZIP/street/polygon artifacts are release snapshots
without effective windows. A dated rate does not establish that the same city or
district covered an address on the supply date.

- Publish boundary validity periods or dated assignments, and distinguish verified
  legal commencement from capture/release timestamps.
- Include annexation and district-change cases with expected authority sets before,
  on and after the transition.
- State the earliest supported date and represent unknown intervals explicitly.

`RegisterBoundaries` cannot reconstruct history. A host needs a suitably dated
boundary source or a reviewed historical release; choosing a release alone does
not prove historical coverage. Data must exist before an adapter can select it.

## 4. Expand mixed sourcing into executable decisions

**Contract gap.** `kind=sourcing`, `payload.basis=mixed` does not specify the place
used by each taxing authority, or the seller/supply facts that select a branch.

- Extend the existing sourcing shape with dated decisions per state/county/city/
  district layer and any seller, establishment, order-acceptance or route facts.
- Distinguish where an authority is selected from where its rate is looked up.
- Supply cases where origin and destination produce different authority sets and
  totals; state missing-fact and unsupported-branch outcomes explicitly.

The engine refuses an identified mixed intrastate case. Pure origin/destination
rules use the tax point. A new branch may not silently fall back to destination.

## 5. Clarify rounding groups in the structured contract

**Contract clarification.** Existing `method`, `places`, `appliesTo` and
`aggregatesLocal` are consumed, including `seller_election`. Orders round once per
rate/authority/policy group and allocate actual tax to lines and authority breakdowns.

- Specify grouping keys, separate-versus-combined authority rounding, treatment of
  different rates, and whether credits net within a group or reverse an original
  assessment. Define intermediate precision and deterministic remainder allocation.
- Absent `aggregatesLocal` differs from `false`: ME omits it because there are no
  local shares. This is not a data error. The engine permits a single state share
  and refuses unspecified aggregation when local components are present.
- Keep invoice/line elections separate from elections replacing tax rates.
- Supply multiplication examples and identify optional bracket alternatives. The
  IL path implements the published multiplication policy, not every collection table.

The proposed grouping fields in the companion contract require agreement; they do
not assert that the current grouping and credit policy is legally universal.

## 6. Complete the connections in the partial territory migration

**Already upstream:** `resources/overlays/eu/territories.json` and
`EuTerritoryNormalizer` publish `eu:PT:MADEIRA` and `eu:PT:AZORES`,
parent jurisdiction links, `inTaxArea`, and dated standard/intermediate/reduced
rates. Madeira carries **22/12/5** through 30 September 2024 and **22/12/4** from
1 October 2024; the earlier opening is marked as an inferred floor. Azores carries
**16/9/4** from 1 July 2021. These rates are not a missing-data request.

| Connection | Current state | Remaining data/contract work | laravel-tax work |
| --- | --- | --- | --- |
| Address → territory | Package postal matches live in `StaticEuTerritories`; territorial rates already have register jurisdiction keys. | Publish dated, sourced postal coverage, normalization, overlaps/precedence and ambiguity outcomes linked to those keys. | Resolve postal/subdivision facts to a territorial key at the tax point; no automatic mainland classification for an unknown address. |
| Territory → VAT area | Cadastre already has parent links and `inTaxArea`; the package has its own territory exclusions. | Link membership to the resolved territory, with validity/coverage and explicit exclusions; separate EU membership, EU VAT area and any local tax regime. | Feed resolved membership into place-of-supply/treatment before selecting a rate. An outside-area flag is not a zero-rate substitution. |
| Category/operation → regional rate | Dated regional bands exist, and the package now READS them: each territorial band is paired with the mainland band of the same kind on the supply date, and a territory the register does not carry refuses. No figures remain in code. | Link category/classification or legal band to the applicable regional rate, with exemptions, seller/place conditions, dates and provenance. Equal percentages must not be assumed to mean the same legal band. | Read regional rates and substitutions without flattening history; preserve exemption/zero treatment and refuse uncovered dates or ambiguous bands. |
| Territorial exceptions | Package logic contains exclusions and limitations for operation- or seller-dependent territories. | Encode relevant seller establishment, operation and route predicates plus coverage; a regional headline rate alone is insufficient. | Evaluate supported predicates; expose unknown/unsupported outcomes. Do not generalise Portugal's band substitution to every territory. |

**Public category API: implemented.** `TaxQuery::$categoryKey` and
`SupplyLine::$categoryKey` take an exact register key, validated against the
installed release (`UnknownCategory` names the closest published keys). It reaches
rate lookup, taxability, orders, the chain and the cache. `TaxClass` still governs
place of supply, derived from the key when not stated.

**`UsLocalStructure`**, what remains and why:

- *Point-resolved states* are no longer a list in use: the geocoder reads them from
  the installed release (a geometry artifact present). The list is only the
  fallback for a geocoder built without a store.
- *County-resolved states* (FL, PA, HI, VA) cannot yet be derived. The register
  labels the local units of Hawaii, Pennsylvania and Virginia `level: territory`,
  and labels Idaho's resort cities and Mississippi's two cities the same way, so a
  derivation would wrongly add ID and MS. **Ask:** publish `county` (or a
  county-equivalent level) for units that are counties, distinct from cities.
- *Philadelphia is coterminous with its county; Virginia's independent cities are
  county-equivalents.* Name-matching facts, stable for decades; they stay until the
  register carries the relation.

**Postal ranges for territories** are not published in any section of release
`2026.09.21-255`, so address → territory remains in `StaticEuTerritories`. Which
territories Article 6 places outside the VAT area also remains there: `inTaxArea`
marks the Canary Islands `true` because IGIC is carried, which is a different fact.

## Extend the existing publication checks

Cadastre already ships **versioned JSON schemas** (`schema/cadastre-1.*.json`,
including `1.34.0`) and the blocking **`RulesDoNotContradict`** check
(`rules-do-not-contradict`), grouped by `Rule::standing()` with inclusive windows.
There is also a blocking rate-overlap check. The requests below extend those checks.

- Include the actor `binds` in threshold identity/standing, as already identified
  and being handled upstream. Test two actors with the same currency/window and
  dates, plus a genuine overlap within one actor; retain category/component scopes.
- Extend schema validation for new predicates, required facts, typed operands,
  separate amount/count comparators, output branches and rounding group semantics.
  Document absent, false, unknown and unsupported distinctly.
- Extend overlap checking to conditional branch scope: mutually exclusive branches
  can coexist; competing applicable branches must block or have explicit precedence.
- Add compatibility cases proving that an older reader refuses a new conditional
  rule **before returning any tax amount**, including an otherwise valid scalar
  `included`, rate or sourcing basis. A capability label an old binary ignores is
  insufficient: use a breaking schema major or a separately versioned endpoint that
  the old compiler already rejects. Never publish a stripped, unconditional variant.
- Add source-truth, historic-boundary and calculation examples alongside schema and
  overlap checks. Their evidence answers different questions.

This package now checks schema compatibility both at compilation and when opening
an installed store, accepts only reviewed schema minors through `1.34.x`, and rejects
unknown fields on consumed rule kinds. This is defensive rejection, not adoption of
the draft predicate vocabulary. Older deployed binaries still need the publication
barrier described in the [consumer contract](cadastre-consumer-contract.md).

**Preserve PA and AZ history and CT/NY `sales_and_transactions`.** Those existing
records exposed reader defects that have been fixed. Passing package tests do not
certify the whole register or resolve the upstream items above.
