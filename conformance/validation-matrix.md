# Data, engine and validation matrix

Reviewed against the local implementation on 18 September 2026. Full `composer qa`
passed on **18 September 2026** after the rule and compatibility fixes: **574 Pest tests, 2,031
assertions**, including the 41 reference cases and the added real-release rule
regression, plus lint, static analysis, licence and dependency checks.
The audit found defects outside the original reference cases. Engine changes and
new rule/compatibility regressions are recorded below; publication work remains in
[cadastre feedback](cadastre-feedback.md). Passing tests do not establish complete
tax-rule coverage.

**Data describes the applicable facts; the engine interprets and applies them.**
A percentage, effective date, category mapping, threshold or jurisdiction boundary
is data even when stored in a PHP class. Selecting a record, evaluating a rule,
resolving an address and calculating an amount are executable logic.

There are two other boundaries to keep visible: the **adapter** compiles and reads
the register; the **host application** supplies transaction facts. A defect in
either can produce a wrong amount with correct published data and arithmetic.

## Responsibility matrix

“Fixture” means controlled, invented data. “Reference” means an externally sourced
expectation assessed through the real register and engine together. Neither label
means exhaustive coverage.

| Area | Data responsibility and current location | Engine / adapter responsibility | Current evidence and remaining gap |
| --- | --- | --- | --- |
| Standard, reduced and zero rates | Published percentages, kinds and category scopes in the register. | [RateResolver](../src/Register/Reader/RateResolver.php) and [RegisterRateSource](../src/Register/Sources/RegisterRateSource.php) choose and translate a record. | 33 official-rate reference cases, including 27 EU standard rates and UK VAT. These validate sampled results through the adapter, not every raw register record. |
| Effective dates and history | The register supplies effective windows; the host supplies the tax point. Boundaries are release snapshots. | Readers select applicable records; regimes use the supply date. | Sourcing and nexus now accept the tax point and filter inclusive windows. PA/AZ transitions have offline and live regressions. NY's published threshold start date still requires a data correction; boundary history remains unavailable. |
| Product classification | Register categories/classifications; host SKU mappings; the package's [CategoryMap](../src/Register/Reader/CategoryMap.php) is a maintained correspondence table. | Map the public `TaxClass`, refine with commodity codes, walk ancestors and flag ambiguity. | Fixture mapping and commodity tests; a few real reduced-rate examples. Raw register category keys are not accepted by the public query, and finer register categories can collapse into one public class. No broad independent product-code comparison. |
| Taxability and price exemptions | Register category records and `price_exemption` payloads specify caps, currency and treatment above the cap. | [RegisterTaxability](../src/Register/Sources/RegisterTaxability.php) interprets them; the regime computes the taxable base and treatment. The taxable fallback is code policy. | Fixture threshold and historical-taxability tests. The three US local-rate references concern ordinary taxable goods; exemption thresholds and conditional products need independent references. |
| Geographic boundaries | Register ZIP, street and polygon artifacts; the host/geocoder supplies locality information. | [RegisterBoundaries](../src/Register/Sources/RegisterBoundaries.php) and `cboxdk/tax-resolver` determine the authority set. | Published conformance cases for KS/AR check format/reader agreement with the register's shared resolver. They are not an independent audit of the boundaries. |
| Local tax components | Register state, county, city and district shares and combined rates. | Stack the applicable shares, avoid double counting, and allocate rounded tax to authorities. | Three US cases check published state/local rates and derived amounts. Seattle's local share is an aggregate represented at city level. The tests start from supplied ZIP/county localities; individual local allocations, geocoding and broad district coverage remain unverified. |
| Place of supply and reverse charge | Country tax profiles from `laravel-geo`; register US sourcing parameters; seller/customer/route facts from the host. | Regimes choose origin/destination and decide the tax treatment. | Fixture place-of-supply tests and one independent DK→FR B2B-service reference. That reference asserts a validated tax ID; it does not call VIES or validate all VAT exceptions. |
| Nexus, holidays and marketplace rules | Register threshold, holiday, election and marketplace parameters. The host supplies registrations, relevant commercial facts and OSS status. | Interpret parameters and apply gates; registration is not inferred from a single invoice. | CT/NY's published AND values and AZ's dated thresholds are now read correctly, with real-release regressions. Unknown operators and overlapping applicable rules refuse. Nexus figures remain advisory; the host's registration assertion governs collection. |
| Inclusive/exclusive prices, rounding and credits | The selected rate and published `rounding` rules are data, including method, decimal places, invoice/line scope and local aggregation. | [TaxRate](../src/ValueObjects/TaxRate.php), [AppliesTaxRate](../src/Regime/Concerns/AppliesTaxRate.php) and [RoundsInvoices](../src/Concerns/RoundsInvoices.php) apply the policy and reconcile amounts. | The US regime now reads half-up/up, precision and scope through [RegisterRounding](../src/Register/Sources/RegisterRounding.php). Regressions cover invoice groups, inclusive/exclusive lines, credits, delivery portions and authority allocations; a real-release IL order rounds to $0.07. Collection-table alternatives remain outside this policy path. |
| Orders and delivery | Register product rates and `taxable_base` rules; the host provides amounts, pricing, allocation basis and facts relevant to conditional exclusions. | [AssessesOrders](../src/Concerns/AssessesOrders.php) determines delivery treatment, splits amounts and reconciles net/tax/gross. | Inclusive delivery is fixed. US transport/handling rules are now consumed through [RegisterDelivery](../src/Register/Sources/RegisterDelivery.php); KS returns zero delivery tax with confirmed exclusion conditions. Missing conditions/coverage and independent delivery rates on exempt goods refuse. Structured predicates remain a cadastre request. |
| Special territories | Cadastre already publishes dated Madeira/Azores bands and parent/`inTaxArea` facts. Postal matches, exclusions and numeric substitutions are still duplicated in [StaticEuTerritories](../src/Territories/StaticEuTerritories.php). | Connect dated postal coverage, VAT membership/exceptions and category/band selection to regional records; extend the public category API to expose exact register keys. | **Partial migration.** Fixture territory tests; absent from the 41 independent references. Missing connections and proposed API work are itemised in [cadastre feedback](cadastre-feedback.md), not recorded as missing regional rates. |
| Conditional-rule compatibility | Cadastre's versioned schemas and blocking overlap checks already exist. New executable conditions need a breaking publication barrier; threshold identity must include actor `binds` (upstream work). | Validate schema at compile and local read, reject unsupported rule fields and preserve unknown/unsupported outcomes. | [Compatibility regressions](../tests/Feature/RegisterCompatibilityTest.php) and delivery assessment tests prove rejection before an unconditional answer. Reviewed schema ceiling is `1.34.x`; the [consumer contract](cadastre-consumer-contract.md) is a proposal, not implemented predicate coverage. |
| US local structure and supported regimes | State lists in [UsLocalStructure](../src/Territories/UsLocalStructure.php); geo tax profiles outside the register. | Select a resolver strategy and require an implemented [regime](../src/Registry/DefaultRegimeRegistry.php). | Fixture locality and coverage tests. A new jurisdiction in the register does not install its calculation logic. Published-data coverage and modelled-regime coverage are separate. |
| Sync, pinning, verification and rollback | Release metadata and installed artifacts, plus the host's deployment configuration. | Compiler, store, pointer and commands maintain a consistent local version. | Operation/install tests and live sync→verify→price. Local hashes detect changed files; they do not establish legal correctness, source freshness or publisher authenticity. |
| External identity and address services | Geocoder results, VAT-ID validation results and the host's evidence. | Adapters validate responses; the host puts the resulting facts into the query. | HTTP-faked Geocodio/VIES/HMRC tests. The 41 references do not exercise these external services live. |
| Fixed levies and return totals | Fixed levy amounts/rules must be supplied by the host; default levy sources are empty. Return inputs are assessments and reporting dates. | Apply supplied levies and aggregate assessed amounts by period and authority. | Fixture levy/return tests. No independent fixed-levy or filing comparison in this corpus. |

## Findings and remediation from the 18 September audit

These findings use installed release **`2026.09.16-236`**. Sourcing and nexus were
checked through the real register adapters against records applicable on
**18 September 2026**. IL was checked through the arithmetic API; KS through the
public order API with a **17 September 2026** tax point. The original observations
are preserved below. [Offline regressions](../tests/Feature/RegisterRuleApplicationTest.php)
and an additional [live regression](../tests/Feature/RegisterReferenceTest.php)
now cover the corrected paths; the 41-case corpus remains separately counted.

| Owner | Case | Original result or verified gap | Status / remaining work |
| --- | --- | --- | --- |
| **laravel-tax adapter / engine** | PA sourcing | An expired `origin` record won over `destination` from 1 January 2026. | **Fixed:** the supply date reaches the sourcing adapter; current and historical windows are tested, including an actual local-rate switch in a fixture. The [PA authority](https://www.pa.gov/agencies/revenue/resources/tax-types-and-information/sales-use-and-hotel-occupancy-tax/local-sales-tax) distinguishes retroactive effect from later enforcement. |
| **laravel-tax adapter** | AZ nexus amount | The first remote-seller record returned **$200,000** instead of the current **$100,000**. | **Fixed:** date selection and the advisory hint follow the tax point; transition endpoints are tested. The [AZ authority](https://azdor.gov/business/transaction-privilege-tax/retail-sales-subject-tpt/out-state-sellers/economic-threshold) confirms the dated amounts. |
| **laravel-tax adapter** | CT and NY nexus combinator | Published `sales_and_transactions` became OR. | **Fixed:** canonical values are decoded and fixtures use them; the real release asserts AND for both states. Unknown operators refuse. Both [CT](https://portal.ct.gov/drs/businesses/new-business-resource-center/registering-with-drs) and [NY](https://www.tax.ny.gov/pubs_and_bulls/publications/sales/nexus.htm) require both limbs. |
| **laravel-tax engine** | IL rounding policy | The published round-up/invoice policy was ignored, producing **$0.06** rather than **$0.07** on the audited state-rate example. | **Fixed:** dated method, precision and scope are applied; two $0.50 lines total $0.07 in the live regression. Offline cases verify credits, mixed pricing and authority allocation. The [Illinois rule, section 150.405](https://www.ilga.gov/agencies/JCAR/EntirePart?titlepart=08600150), also permits a collection table; this path implements the published multiplication policy. |
| **laravel-tax engine + data/host contract** | KS delivery | A $10 delivery inherited goods taxation and produced **$0.91 tax**, ignoring the published base exclusion. | **Engine path fixed:** the real-release order returns $0 with confirmed exclusion conditions. Unknown facts refuse, and transport/handling remain distinct. [KS guidance](https://www.ksrevenue.gov/pub1510.html) supplies the conditions; cadastre still needs structured predicates and historical coverage. |
| **Cadastre data** | NY remote-seller effective date | The published remote-seller threshold starts **1 June 2019**, marked as an actual start rather than a capture floor. Its provenance uses a marketplace-provider date. | Correct the upstream record and provenance: [TSB-M-19(4)S](https://www.tax.ny.gov/pdf/memos/sales/m19-4s.pdf) gives **21 June 2018** retroactive effect for the $500,000 remote-seller threshold. **1 June 2019** concerns [marketplace providers](https://www.tax.ny.gov/pdf/memos/sales/m19-2-1s.pdf). |
| **Data coverage + adapter capability** | Historical local boundaries | Installed boundary artifacts are snapshots; `RegisterBoundaries` ignores the requested date. No independent historic boundary result was validated. | Publish historical boundary coverage and make the adapter select it, or expose the unsupported period explicitly. A code change alone cannot reconstruct missing boundaries. |

The nexus defects affect the exposed threshold figures and explanatory hints.
`NexusThreshold` does not determine whether a seller has crossed a threshold;
the host's explicit registrations govern collection in this package.

For KS, zero tax remains conditional on the delivery facts stated above. A host
confirmation is not a blanket exemption. For IL, the live example uses the state
rate without an address-level local determination; it verifies rounding, not a
complete local tax quote or all legally permitted collection methods.

The original fixture suite missed these defects: its AZ case omitted the older
rows, and its CT/NY cases used a different vocabulary. The new regression suite
covers the actual shapes, dated transitions and failure paths. Publication truth
still needs separate source checks. A `startIsFloor` date remains distinct from a
verified legal commencement date; no history is invented before it.

## What the 41 cases establish

All 41 are executed by [RegisterReferenceTest](../tests/Feature/RegisterReferenceTest.php)
through application-container bindings after syncing a real release. They are
**integration cases with independent expectations**, not 41 isolated data tests or
41 isolated engine tests. They execute inside **one Pest test**.

The groups below are disjoint and match `kind` in the
[reference JSON](reference/2026-09-17.json):

| Reference kind | Cases | Main question | What a pass does not establish |
| --- | ---: | --- | --- |
| `official-rate` | 33 | Do rate data, date/category selection and the resulting amounts agree with the cited facts? | Which individual layer caused a mismatch; completeness of all rates and categories. |
| `official-local-rate` | 3 | Do supplied localities resolve to published state/local rates and the resulting derived amounts? | Independent boundary/geocoding validation, all product taxability or the split within a published local aggregate. |
| `official-example` | 2 | Does the engine reproduce HMRC inclusive pricing and the Finnish delivery example using real rates? | Every national rounding or delivery-allocation rule. |
| `arithmetic-regression` | 2 | Do Danish inclusive pricing and credit amounts reconcile at the cited rate? | Published transaction results; the expected amounts are derived arithmetic. |
| `official-rule` | 1 | Does the stated B2B-services scenario receive reverse-charge treatment? | Rate quality or actual VAT-ID validation; the treatment needs no percentage lookup. |
| **Total** | **41** | **Sampled agreement of the assembled system.** | **Separate certification of the data and the engine.** |

The 574-test total includes fixture, adapter and live tests. It is not a percentage
of tax-law coverage and should not be reported as separate data/engine pass counts.

## Separating validation and failures

| Validation layer | What is held fixed | What is checked | Status in this package |
| --- | --- | --- | --- |
| Published data | Source date, classifications and independently verified facts. | Raw/normalized records, provenance, effective windows, missing/duplicate/conflicting entries and freshness; no invoice arithmetic. | Cadastre already has versioned JSON schemas and blocking rule/rate overlap checks; the feedback asks for extensions. No dedicated independent source-truth audit suite is demonstrated here. Current external rate checks pass through the engine. |
| Engine behaviour | Controlled rates/rules and transaction inputs. | Decisions, formulae, rounding, overrides, failures and reconciliation. | Existing fixture suites. They validate behaviour against chosen inputs, not those inputs' real-world truth. |
| Adapter and deployment | Known raw artifacts or a pinned release. | Parsing, normalization, compilation, authority resolution, activation and faithful lookup. | Fixture operation/reader tests and live conformance/install paths. Shared-resolver agreement is consistency evidence. |
| Assembled calculation | Engine code/dependencies, register release, tax point and full transaction facts. | Final treatment, rate, component rates, net/tax/gross and provenance against external expectations. | 41 reference cases based on tax-authority sources, plus a live rule regression. Pricing is blocked from HTTP after sync. Published facts, host assertions and derived arithmetic are identified separately. |

For a mismatch, inspect the raw record first, then the selected/normalized record,
then the decision and arithmetic. A wrong published value is a **data defect**;
a correct record read or selected incorrectly is an **adapter/engine defect**;
correct selected facts with a wrong total are an **engine defect**. A wrong SKU,
registration assertion, date or locality can instead be a **host-input defect**.
Multiple faults can coexist; one changed total does not identify its owner.

To investigate change impact, keep query, tax point and dependencies controlled:

| | Recorded data release | Candidate data release |
| --- | --- | --- |
| Recorded engine revision | Baseline | Isolates the effect of a data-release change on the same engine. |
| Candidate engine revision | Isolates the effect of an engine/adapter change on the same data. | Checks the proposed combination and interactions. |

This four-way comparison is a diagnostic method, **not an already implemented CI
job**. `CBOX_TAX_REFERENCE_RELEASE=latest composer test:reference` exercises a data
variation with the checked-out engine; it does not compare engine revisions.
Resolve and record the concrete release behind `latest` for reproducible results.

The [reference report](reference/README.md) records the tax-authority sources,
derived expectations, inputs and the delivery defect. A mismatch should be assigned only
after identifying the layer, not automatically to the register or the calculator.
