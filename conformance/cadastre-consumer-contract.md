# Proposed cadastre → laravel-tax consumer contract

**Discussion draft, 18 September 2026. Not an agreed schema or an implemented
predicate API. Every new field, type and capability name below is a proposal.**
Existing names are identified explicitly. JSON examples and proposed outcomes are
acceptance targets for a future implementation, not executable tax fixtures or
claims of current jurisdiction coverage. See [feedback and ownership](cadastre-feedback.md).

## Existing vocabulary and the proposed additions

| Existing contract | Meaning to preserve | Proposed extension |
| --- | --- | --- |
| `schemaVersion`, versioned JSON schemas | Published document compatibility. | Breaking-version publication barrier; later, a mandatory `requiredCapabilities` list understood by participating readers. |
| `effective.from`, `effective.until`, `startIsFloor`, provenance | Inclusive legal/coverage windows; a floor must not invent earlier history. | Explicit `coverage` intervals with unknown gaps for facts, rules and boundaries. |
| Threshold `binds` | The actor the threshold binds. | Include the actor in identity/standing; upstream owns this correction. |
| Threshold `crossing` | What crossing does: e.g. `must_register`, `platform_becomes_liable`, or `unstated`. | No change of meaning; never use it as a comparator. |
| Threshold `consequence`, `graceDays` | When the resulting duty starts, where stated. | Structured timing rules if the existing fields cannot represent it. An unstated timing/effect remains unknown. |
| `amount`, `currency`, `transactions`, `combinator` | Separate threshold limbs and how they combine. | `amountOperator` and `transactionsOperator`, independently required for their respective limbs. |
| `measuredOver`, `basis`, `counts` | Period and sales basis. | `measurementWindow` with explicit start/end, calendar/time zone, included/excluded sales and an evidence reference. |
| `rate.conditions`, threshold `conditions` / `conditionsCombinator` | Existing typed textual rate qualifications and compound threshold shapes. | A separately specified executable `decision` tree; do not reinterpret the existing arrays as this new language. |
| Rounding `method`, `places`, `appliesTo`, `aggregatesLocal` | Existing policy parameters. | `roundingGroup`, credit handling and allocation policy. |

The comparison vocabulary `exceeds`, `at_least`, `below`, `at_most` already exists
on **rate conditions**. Reusing those values for new **threshold** fields is a
proposal; their presence elsewhere does not mean threshold comparisons exist today.

## Input facts and ownership

The host supplies transaction evidence; cadastre publishes legal predicates and
coverage; the reader resolves records; the engine evaluates and computes. A host
must not supply a desired tax answer as if it were a source fact.

All paths in this table are **proposed input names**. Amounts and rates are decimal
strings, counts are integers, dates are ISO dates with a declared legal time zone,
and booleans are true/false/null. Missing and null mean unknown, not false or zero.
An explicitly empty, verified set can be known-empty; an omitted set cannot.

| Proposed input | Required content and purpose |
| --- | --- |
| `context.release`, `context.taxPoint`, `context.currency` | Pin one immutable release for the whole calculation, date and monetary unit. |
| `seller.actor`, `seller.establishments`, `seller.registrations`, `seller.elections` | Actor, dated presence, registration and applicable elections. Do not infer registration from a single invoice. |
| `customer.kind`, `customer.taxIdEvidence`, `customer.exemptionEvidence` | Relevant status and dated evidence, only for rules that require it. |
| `route.shipFrom`, `route.shipTo`, `route.orderAcceptedAt`, `route.supplyKind` | Addresses/coordinates, postal precision, origin/destination and operation facts for sourcing and territorial exceptions. |
| `lines[].id`, `lines[].categoryKey`, `lines[].classification`, `lines[].amount`, `lines[].pricing`, `lines[].quantity` | Stable allocation key, exact register category plus any nomenclature code, signed amount, inclusive/exclusive basis and quantity/unit. A class mapping must retain uncertainty. |
| `delivery.component`, `delivery.separatelyStated`, `delivery.costIsTrueAndReasonable`, `delivery.relatedLines` | Component-specific facts and evidence; relationship to taxable, exempt and partially exempt goods. |
| `nexus.actor`, `nexus.salesAmount`, `nexus.transactionCount`, `nexus.measurementWindow`, `nexus.salesBasis` | Totals measured for the correct actor, place and complete period, with currency and basis matching the rule. Partial totals or incompatible periods cannot answer a threshold test. |
| `rounding.election`, `adjustment.originalAssessment` | A permitted invoice/line election; reference to original amounts/groups for reversals when the rule requires it. |

The engine derives taxable portions and resolved authority sets from these facts
and published rules. Host-supplied locality assertions retain their provenance.
A historical tax point also needs dated boundary/territory coverage; a current
address match is insufficient evidence of a historical assignment.

## Conditions and outcomes

**Proposed syntax** for a synthetic delivery rule (not a full transcription of KS):

```json
{
  "requiredCapabilities": ["decision-tree-v1"],
  "decision": {
    "all": [
      {"fact": "delivery.separatelyStated", "op": "eq", "value": true},
      {"fact": "delivery.costIsTrueAndReasonable", "op": "eq", "value": true}
    ],
    "onTrue": {"baseTreatment": "excluded"},
    "onFalse": {"baseTreatment": "included"},
    "onUnknown": {"status": "unknown"}
  }
}
```

`all`, `any`, `not`, `fact`, `op`, `value`, branch fields and outcome fields above
are proposed. Each predicate needs typed operands, units where relevant, a source
reference and a declared fact path. There is no executable source code or prose
evaluation in the record. Validate the **whole tree** before evaluating branches.
Unknown operators, fact vocabulary, node types or required capabilities produce
`unsupported`, even if short-circuiting could appear to avoid the unknown node.

For supported nodes use three-valued logic: `all(false, unknown)=false`,
`all(true, unknown)=unknown`, `any(true, unknown)=true`,
`any(false, unknown)=unknown`, `not(unknown)=unknown`. Every decisive false result
must have an explicit supported false branch. An exclusion predicate failing does
not imply inclusion unless the publication actually supplies that branch.

| Proposed `status` | Meaning | Proposed output discipline |
| --- | --- | --- |
| `resolved` | Supported rule, sufficient evidence, deterministic applicable branch. | Return decision, selected rule/rate IDs, release, effective window, fact references and, for a calculation, amounts/groups. A legitimate zero must have an explicit reason. |
| `not_applicable` | Scope or a supported predicate proves this rule does not apply. | No tax answer from this rule. Another rule may answer only through a declared, covered resolution path. |
| `unknown` | Required fact, published operator, measurement basis or dated coverage is missing/unstated. | Return `missingFacts` / `missingCoverage` and `tax: null`; no completed assessment. |
| `unsupported` | The required semantics or schema are not understood, or the required computation is not implemented. | Return `unsupportedFeatures` and `tax: null`; no completed assessment. |
| `conflict` | Multiple applicable records/branches contradict or overlap without a defined resolution. | Return conflicting IDs and `tax: null`; do not pick the first record. |

These are **proposed logical outcomes**, not current exception names. Today the
package uses `UnresolvedTaxRule` / `RefusalReason`, and incompatible store schemas
raise `DatasetUnreadable`. The implementation can retain exceptions if the public
adapter maps them without inventing amounts. Failed order assessments must not
present a partial sum as the invoice tax. A diagnostic subtotal must be labelled
incomplete and must not occupy the final amount field.

## Threshold comparison and timing

Proposed extension of the existing NY payload shape:

```json
{
  "amount": "500000.00",
  "currency": "USD",
  "amountOperator": "exceeds",
  "transactions": 100,
  "transactionsOperator": "exceeds",
  "combinator": "sales_and_transactions",
  "binds": "remote_seller",
  "measuredOver": "preceding_four_tax_quarters",
  "crossing": "unstated"
}
```

Only `amountOperator` and `transactionsOperator` are new fields in this example.
It deliberately keeps the published `crossing=unstated`; no obligation is inferred
from that value. Compare decimal monetary values exactly, without floats, cent
rounding of totals, or implicit currency conversion. Compare the integer count
separately. Define `exceeds` as `>`, `at_least` as `>=`, `below` as `<` and `at_most`
as `<=`. A missing/unstated operator yields unknown; an unrecognised operator yields
unsupported. Never default either limb to `>=`. No transaction limb is different
from a transaction limb with an unknown count/operator.

A threshold result and an obligation start are separate outputs. Even a known
`thresholdMet=true` cannot establish what duty starts when `crossing` is unstated,
or its commencement when timing is unstated. The companion nexus/host logic must
supply complete period/basis facts; `NexusThreshold` in laravel-tax remains advisory.
Keep CT/NY AND and AZ's dated amounts. Correct NY dates upstream, never in a consumer
selector or fixture presented as a verified corrected release.

## Territory and category resolution

Proposed pipeline: dated address coverage → territorial jurisdiction → dated VAT
area membership/exceptions → category/operation decision → regional rate/band.
Each link needs its own provenance and supported period. An unknown postal match
must not become mainland; an outside-VAT-area result must not become a territorial
0% rate. Import/local-tax obligations remain distinct from the EU VAT decision.

Cadastre already publishes Madeira/Azores regional bands and parent/membership
facts. Connect those facts to postal resolution and explicit category/band
substitutions. Select by legal band/category, not solely by the mainland numeric
percentage; retain exemption and zero-rating distinctions. A source floor or a gap
must not be bridged with today's percentage. Operation- or seller-dependent
exceptions need their predicate facts before a substitution can be resolved.

**Proposed public API:** accept a `TaxCategory` value object carrying a register key
alongside existing `TaxClass` values. This name/signature is not committed. Update
`TaxQuery`, `SupplyLine`, `TaxOrder::queryFor`, classification/catalogue mapping,
`TaxRateSource`, `CommodityRateSource`, `ProductTaxability` and their implementations.
Carry the exact category through delivery portions and territorial selection; cache
keys must include it and the release/date and other facts on which a result depends.

Keep the legacy enum as an adapter. Validate raw keys against the pinned vocabulary;
unknown keys refuse. Only follow published parent relationships when that broader
answer is valid for the requested category; an unevaluated narrowing condition
cannot become an authoritative answer. A fine key must not be converted back to a
coarse enum. Category acceptance cases must include two children of
`goods.medical_equipment` with distinct synthetic treatments, the old
`TaxClass::MedicalDevice` mapping, an unknown key, and a condition on a parent rate.

## Rounding groups

Proposed extension, using existing policy fields plus a **new** `roundingGroup`:

```json
{
  "method": "half_up",
  "places": 2,
  "appliesTo": "invoice",
  "aggregatesLocal": true,
  "roundingGroup": {
    "keys": ["invoice", "currency", "taxType", "jurisdiction", "authoritySet", "rate", "policy"],
    "credits": "net_within_group",
    "allocation": "largest_remainder_then_line_id"
  }
}
```

Every nested name/value in `roundingGroup` is proposed. The invariant is explicit
separation of rates, currency, tax type, jurisdiction, authority composition and
rounding policy. Numerically equal decimal rate spellings identify one rate, but
identical totals from different authorities do not identify one group. If scope is
`item`, add line identity; if `seller_election`, the host supplies an allowed choice.
Whether tax-point changes require separate groups must follow dated policy/rate
identity, not merely equal numeric percentages.

For exclusive lines, calculate exact tax from taxable net; for inclusive lines,
calculate exact tax as gross minus gross divided by one plus the fractional rate.
Keep rational/decimal precision until the stated rounding boundary. Combined local
aggregation rounds the combined liability once; separate authority rounding needs
one group per authority. Absent `aggregatesLocal` can describe a state-only charge,
but cannot instruct local aggregation. Unsupported separate aggregation refuses.

The proposed netting mode totals signed exact liabilities within the same group,
rounds once, then allocates the difference using signed remainders and stable line
IDs. Preserve net for exclusive lines and gross for inclusive lines, reconcile tax
components and enforce net + tax = gross. Define `up` explicitly for negative
amounts too: the current engine uses rounding away from zero, not mathematical
ceiling. An alternative `credits=reverse_original` would require original assessed
tax and original groups, and must not be silently treated as netting.

Current `RoundsInvoices` already groups by rate, place, authority composition and
policy and reconciles allocations. The expanded contract still requires agreement
on legal grouping and credit semantics; it is not a new claim of universal coverage.

## Representative input/output cases

All proposed output names in this section are illustrative. Monetary values below
are arithmetic from the stated synthetic inputs. They are not new official tax
examples, and proposal-only cases are not counted as passing engine regressions.

### Threshold limbs

Use the NY comparison example above at `taxPoint=2026-09-18`, with a complete,
matching four-sales-tax-quarter window, the correct sales basis, actor and USD.

| `salesAmount` | `transactionCount` | Proposed output |
| ---: | ---: | --- |
| `500000.00` | 101 | `resolved`, `thresholdMet=false` (amount equality fails `>`). |
| `500000.01` | 100 | `resolved`, `thresholdMet=false` (count equality fails `>`). |
| `500000.01` | 101 | `resolved`, `thresholdMet=true`; duty effect remains unknown because `crossing=unstated`. |
| `500000.01` | null | `unknown`, `missingFacts=[nexus.transactionCount]`; no obligation verdict. |
| `500000.01` | 101, but different measurement window | `unknown`, incompatible window; no obligation verdict. |
| `500000.00` | 100, synthetic operators both `at_least` | `resolved`, `thresholdMet=true`; demonstrates independent comparator semantics, not NY law. |
| Any totals | Valid count, unknown `transactionsOperator=approx` | `unsupported`; no obligation verdict. |

At `2018-06-21` the **current published NY window** has no applicable remote-seller
row. The present reader must continue returning no advisory threshold; the proposed
coverage-aware evaluator would return `unknown`, not "no nexus". An upstream date
correction needs a new release and its own historical acceptance cases.

### Delivery predicate

Use the synthetic tree above, charge `10.00 USD`, exclusive pricing, taxable goods,
a separately resolved combined rate of `6.5%`, and line rounding at two places.

| `separatelyStated` | `costIsTrueAndReasonable` | Proposed output |
| --- | --- | --- |
| true | true | `resolved`, `excluded`, net `10.00`, tax `0.00`, gross `10.00`. |
| false | true | `resolved`, `included`, net `10.00`, tax `0.65`, gross `10.65`. |
| true | null | `unknown`, `missingFacts=[delivery.costIsTrueAndReasonable]`, `tax=null`. |
| false | null | `resolved`, false branch: tax `0.65`, because supported `all(false, unknown)` is false. |
| true | true, but tree contains an unrecognised operator | `unsupported`, `tax=null`; no host confirmation bypass. |

Additional acceptance inputs: related goods with taxable net `200.00` and exempt
net `100.00`, delivery `30.00`, explicit net-value apportionment and synthetic
rules including only the taxable-goods portion at `6.5%` → portions `20.00/10.00`,
tax `1.30/0.00`, total `1.30`. If the rule instead taxes delivery of exempt goods
and no independent delivery rate is provided → `unsupported`, `tax=null`.
Partially exempt goods require their actual taxable base and an explicit allocation
rule; classifying the whole item as a taxable boolean is insufficient.

### Rounding and signed adjustments

| Synthetic input and policy | Proposed output |
| --- | --- |
| Lines `a=0.50`, `b=0.50` USD exclusive, same state-only `6.25%`, invoice, `up`, two places | Exact group tax `0.0625`; tax `0.07`, allocated `a=0.04`, `b=0.03`; reversing input order changes nothing. |
| Same amounts, same policy, but rates `6.25%` and `5%` | Two groups; tax `0.04 + 0.03 = 0.07`. Rounding the combined exact `0.05625` once to `0.06` is prohibited by this grouping. |
| Two lines `-0.50`, same `6.25%` group, invoice `up`, netting | Group tax `-0.07`, allocated `-0.04/-0.03`, preserving signed reconciliation. |
| Lines `1.00` and `-0.50`, same `6.25%` group, invoice `up`, netting | Exact tax `0.03125`, group tax `0.04`. |
| The same mixed signs but `credits=reverse_original`, no original assessment | `unknown`, `missingFacts=[adjustment.originalAssessment]`, `tax=null`. An engine lacking reversal support reports `unsupported` first. |
| State + local components, `aggregatesLocal` absent | `unsupported`, `tax=null`; a state-only fixture may use the stated policy. |

### Territory, sourcing and coverage

| Proposed input with stated fixture data | Proposed output |
| --- | --- |
| PT, postal `9000-001`, dated mapping to Madeira, category mapped to reduced band, `100.00 EUR` exclusive, `2024-09-30` | `resolved`, `eu:PT:PT-30`, inside VAT area, rate `5%`, tax `5.00`. |
| Same fixture at `2024-10-01` | Rate `4%`, tax `4.00`. The regional bands already exist upstream; the postal/category links are proposed. |
| PT, postal `9500-001`, dated mapping to Azores, intermediate band, `100.00 EUR`, `2021-07-01` | `resolved`, `eu:PT:PT-20`, rate `9%`, tax `9.00`. |
| Same Azores input at `2021-06-30`, fixture has no older window | `unknown`, missing regional rate coverage; no backwards extension of `9%`. |
| PT, missing postal/subdivision evidence | `unknown`, unresolved territory; no mainland substitution. |
| Synthetic rule: state/county from origin, city/district from destination; origin rates `4%/1%`, destination `2%/0.5%`, base `100.00` | `resolved` under a future layer-aware reader: four identified authorities, combined `7.5%`, tax `7.50`. Current mixed-sourcing reader: `unsupported`. |
| Same rule, origin unknown | `unknown` for a capable reader; no destination-only fallback. |
| Historic supply before a boundary artifact's known coverage | `unknown`, missing boundary interval, `tax=null` even if the rate is dated. |
| Any excluded territory, known EU VAT membership exception but unknown local tax regime | Resolve the EU VAT area decision separately; a requested local tax calculation is `unsupported`, not a universal zero-tax quote. |

## Compatibility: old readers must stop

1. **Publication barrier for existing binaries.** New predicates which change a
   previously unconditional rule are a breaking semantic change. Publish them under
   a new schema major (for example an illustrative `2.0.0`, not an agreed version)
   or a separate contract endpoint whose incompatible schema the old compiler
   rejects. Cadastre already has versioned schemas; extend that mechanism. Do not
   send new conditions to an old reader as an additive same-major field.
2. **Capabilities after negotiation.** A proposed mandatory `requiredCapabilities`
   list can govern granular support in participating readers, but cannot protect
   binaries that ignore the list. Rule-level requirements must roll up to release
   requirements, including transitive dependencies on geography and rounding.
3. **Check twice.** Refuse before compiling/activating an incompatible release and
   again before opening a compiled store. Deployments can share stores across
   different reader versions. Preserve schema/requirements and condition trees
   losslessly through compilation; offline pricing must enforce the same contract.
4. **No downgrade by deletion.** Never drop a predicate, unknown branch, rounding
   group or required fact and retain the old scalar rate/boolean. Never skip an
   unsupported narrow rule and accept its unconditional parent. Keep the old pinned
   release only as an explicit deployment choice, not a hidden runtime fallback.
5. **Refuse before answering.** Validate supported features and full tree shapes
   first. Unknown input then yields unknown; unknown semantics yields unsupported.
   Both stop a dependent line/order. Metadata-only additions need an explicit safe
   extension space so readers can distinguish them from executable semantics.

**Implemented defensive boundary in this package:** both compiler and local reader
accept reviewed schema `1.0.x` through `1.34.x` and reject newer minors/majors,
malformed or missing versions. This conservative minor ceiling is a local policy;
new minor releases require review and a package update. Consumed rule kinds reject
unknown envelope/payload fields, including future conditional fields accidentally
labelled as the old schema. Known textual rate qualifications and existing compound
threshold fields retain their current meanings; these checks are not a general
predicate evaluator or a replacement for cadastre's schema validation.

Rule-field rejection is conservative over the selected jurisdiction/kind records,
and at compilation over all downloaded consumed kinds, including inactive windows:
unknown scope semantics cannot safely be discarded. Untouched older binaries do
not gain these protections retroactively; the breaking publication barrier remains
mandatory. Do not mistake a passing current-schema test for that rollout guarantee.

The executable regressions are in [RegisterCompatibilityTest](../tests/Feature/RegisterCompatibilityTest.php)
and [RegisterRuleApplicationTest](../tests/Feature/RegisterRuleApplicationTest.php).
They prove schema refusal at sync and offline read, unchanged active-store selection,
unknown-field rejection before delivery assessment, actor separation and no NY date
override. Future contract examples above require a separately agreed implementation.
