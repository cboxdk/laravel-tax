<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Brick\Math\BigDecimal;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\RateBasis;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxabilityTreatment;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\RateComponent;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * Resolves US sales-tax rates from the us-tax-data dataset. It answers ONLY for US
 * jurisdictions (returning null otherwise, so a composed {@see ChainTaxRateSource}
 * falls through to the other sources for the rest of the world), and resolves at
 * three levels of precision:
 *
 *  1. A category with a `reduced_rate` treatment (e.g. grocery at 2%) returns that
 *     reduced rate directly — a category rule, applied whatever the location.
 *  2. When the jurisdiction carries a rooftop {@see LocalityCode},
 *     EVERY local record the state's boundary index says applies there is stacked
 *     with the state share into an all-in rate (respecting the state's
 *     {@see RateBasis}) at {@see Confidence::Authoritative}. A `zip9` locality is
 *     expanded through that index, so a Kansas City address correctly picks up the
 *     county AND the city; a caller supplying an authority code directly gets that
 *     one authority. See docs/coverage/us-tax-dataset.md.
 *  3. Otherwise the authoritative STATE rate is returned at {@see Confidence::Derived}
 *     — honest that it is the state share, not a rooftop all-in figure.
 *
 * A stacked rate carries its {@see RateComponent}s, so a consumer can remit per
 * authority; a state-only or reduced-category rate carries none, because there is
 * no split to report rather than because one authority takes everything.
 *
 * Deny-by-default throughout: an unknown state, a no-sales-tax state, or an
 * unavailable dataset yields null, and the engine denies rather than assuming 0%.
 */
readonly class UsTaxDatasetRateSource implements TaxRateSource
{
    private const string SOURCE = 'us-tax-data';

    /**
     * The locality scheme carrying a full ZIP+4 (`66101-3064`) — a postal key the
     * boundary index expands into the authorities that apply there, rather than an
     * authority code itself.
     */
    public const string ZIP9_SCHEME = 'zip9';

    /**
     * The locality scheme carrying a county NAME (`Alachua County`) for the states
     * in {@see countyResolvedStates()}, resolved to an authority code by the dataset.
     */
    public const string COUNTY_SCHEME = 'county';

    /**
     * The states where the COUNTY is the only local authority that can apply, so
     * resolving the county resolves the rate exactly — no boundary file needed.
     *
     * This is a legal claim about each state's taxing structure, not an observation
     * of today's data, which is why it is a written list with its grounds rather
     * than something derived from the records:
     *
     *  - **FL** — ch. 212.055 authorizes the discretionary sales surtax to COUNTIES.
     *    The Department of Revenue's own surtax table is published per county, all 67.
     *  - **PA** — only two local taxes exist: Allegheny County at 1% and Philadelphia
     *    at 2%. Philadelphia is carried as a city because that is what it is called,
     *    but the city and the county are coterminous, so a county resolves it.
     *  - **HI** — the counties may adopt a GET surcharge by ordinance; four have.
     *  - **VA** — a Virginia city is by law INDEPENDENT of any county, so a city
     *    there is a county-equivalent (FIPS class C7) rather than something sitting
     *    inside one. Nothing can be below it. The state's own rate page bands all 39
     *    localities by county or independent city, and the dataset carries 39.
     *
     * VIRGINIA ALSO SHOWS WHY THE NAME MATCH IS ORDERED. `Fairfax County` and
     * `Fairfax City` are different authorities over different ground, and so are
     * Franklin, Richmond and Roanoke. A match that dropped the unit word would make
     * each pair ambiguous and refuse — costing Fairfax its regional rate for no
     * reason. {@see UsTaxDataset::localCodeForCounty()} tries the full name first.
     *
     * SOUTH CAROLINA IS DELIBERATELY ABSENT and the reason is the point of this
     * list. Its local option taxes look county-level, and 46 of the dataset's 47 SC
     * authorities are counties — but Myrtle Beach levies its own 1% Tourism
     * Development tax ON TOP of Horry County's. Resolving only the county there
     * would UNDER-charge, which is the direction that cannot be refunded later. A
     * state joins this list when nothing can sit below the county line, not when
     * almost nothing does.
     *
     * Two guards hold the list to that claim, and they sit at different layers on
     * purpose. `tests/Feature/CountyResolvedRateTest.php` checks the engine's
     * behaviour here; `bin/check-county-resolved.php` in the us-tax-data repo checks
     * the PUBLISHED data every drift run, which is where a newly-adopted city tax
     * would actually show up. Adding a state means changing both.
     *
     * @return list<string>
     */
    public static function countyResolvedStates(): array
    {
        return ['US-FL', 'US-PA', 'US-HI', 'US-VA'];
    }

    /**
     * Authority codes that are NOT counties but are coterminous with one, so a
     * county resolution reaches them correctly.
     *
     * Philadelphia is the only NAMED one, and it is named because it is a one-off:
     * a single consolidated city-county in a state whose other authority is an
     * ordinary county. It exists so the guard can tell "a city that IS the county"
     * apart from "a city inside a county" — the distinction that keeps South
     * Carolina out over Myrtle Beach.
     *
     * Virginia is handled by rule instead, in {@see countyEquivalentCityStates()},
     * because there it is not an exception but the entire structure.
     *
     * @return list<string>
     */
    public static function coterminousCityCounties(): array
    {
        return ['US-PA:Philadelphia'];
    }

    /**
     * States where EVERY city is a county-equivalent, so a city-level record needs
     * no individual exemption.
     *
     * Virginia only. Under Virginia law every municipality incorporated as a city is
     * independent of any county — there is no such thing as a Virginia city inside a
     * county, which is why the Census treats all 38 as county-equivalents. Listing
     * the 17 that levy a regional rate would read as 17 exceptions to a rule; there
     * is no rule for them to be exceptions to.
     *
     * Note this covers CITIES, not TOWNS. A Virginia town IS inside a county, so a
     * town-level record would be a genuine sub-county authority and the guard fails
     * on it — correctly.
     *
     * @return list<string>
     */
    public static function countyEquivalentCityStates(): array
    {
        return ['US-VA'];
    }

    public function __construct(
        private UsTaxDataset $dataset,
        private LocalAuthorityResolver $authorities = new DefersLocalAuthorities,
    ) {}

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        if ($jurisdiction->country->value !== 'US' || $jurisdiction->subdivision === null) {
            return null;
        }

        $state = $jurisdiction->subdivision->value;

        // A reduced-rate category rule replaces the STATE share, and — this is the
        // part that was wrong — does not replace the local ones. Missouri's 1.225%
        // grocery rate is its state share; local sales taxes still apply to food,
        // so returning 1.225% all-in under-charged by most of the true rate.
        $reduced = $this->reducedStateShare($state, $category);

        // The host's resolver is asked FIRST, and about the whole jurisdiction
        // rather than about a locality. A Colorado address carries no locality at
        // all — nothing this package ships resolves Colorado below the state line,
        // which is precisely why a host would bind a resolver there. Gating the
        // seam behind a locality would close it to the states that need it most.
        //
        // It also wins over the shipped resolution where both could answer: binding
        // one is a deliberate act, and a host with a state portal's own answer has
        // a better one than a postal-key index.
        $resolved = $this->authorities->authoritiesFor($jurisdiction, $at);

        if ($resolved !== null) {
            $stacked = $this->stacked($state, $resolved, $at, $reduced);

            if ($stacked !== null) {
                return $stacked;
            }
        }

        $locality = $jurisdiction->locality;
        $atRooftop = $locality !== null && $locality->subdivision->value === $state;

        if ($atRooftop) {
            $codes = $this->codesFor($state, $locality);

            // An EMPTY list is a positive answer — the index says no local authority
            // applies there, as in Indiana, which levies none. That is an all-in
            // rate that happens to equal the state share, not a fallback to it.
            $stacked = $codes === null ? null : $this->stacked($state, $codes, $at, $reduced);

            if ($stacked !== null) {
                return $stacked;
            }
        }

        if ($reduced !== null) {
            // No locality resolved, so this is the state share of a rate that may
            // have local food taxes on top of it — the same position the general
            // state rate is in, and it gets the same honest confidence. It used to
            // claim Authoritative, which said the opposite.
            return new TaxRate($reduced, RateKind::Reduced, self::SOURCE, Confidence::Derived);
        }

        return $this->stateRate($state, $at);
    }

    /**
     * The STATE share for a category the state reduces (e.g. groceries), as a
     * percentage string. Null when the category carries no reduced-rate rule.
     */
    private function reducedStateShare(string $state, TaxClass $category): ?string
    {
        $key = $category->datasetCategory();

        // A class the US dataset has no category for — lodging, passenger
        // transport, utility supply — has no reduced-rate rule either, because it
        // has no rule at all. Asking for one under a made-up key would answer for
        // a different product.
        if ($key === null) {
            return null;
        }

        $determination = $this->dataset->taxability($state, $key);

        if ($determination === null || $determination->treatment !== TaxabilityTreatment::ReducedRate) {
            return null;
        }

        return $this->fractionToPercent($determination->conditions['rate'] ?? null);
    }

    /**
     * The local jurisdiction codes that apply at a locality.
     *
     * A `zip9` locality is a postal key, not an authority: it is resolved through
     * the state's boundary index, which may return SEVERAL codes (a county and a
     * city both apply in Kansas City), an empty list (none apply, as everywhere in
     * Indiana) or nothing at all. Any other scheme is already a jurisdiction code
     * and is used as-is.
     *
     * A `county` locality is a county NAME, resolved to the single authority that
     * taxes there. It carries exactly one code by construction — that is what makes
     * {@see countyResolvedStates()} a closed list rather than a heuristic — so
     * unlike a ZIP+4 there is no stacking to do and nothing that can be partially
     * resolved.
     *
     * Null means UNKNOWN and the caller falls back to the state rate; an empty list
     * means the index positively answered "no local authority", which is an all-in
     * rate in its own right.
     *
     * @return list<string>|null
     */
    private function codesFor(string $state, LocalityCode $locality): ?array
    {
        if ($locality->scheme === self::COUNTY_SCHEME) {
            $code = $this->dataset->localCodeForCounty($state, $locality->value);

            // An unmatched or ambiguous county name yields null, NOT an empty list.
            // Empty would mean "the county levies nothing", which reads as an
            // authoritative all-in rate; this is "we could not tell which county",
            // which has to fall back to the state share.
            return $code === null ? null : [$code];
        }

        if ($locality->scheme !== self::ZIP9_SCHEME) {
            return [$locality->value];
        }

        [$zip5, $plus4] = array_pad(explode('-', $locality->value, 2), 2, '');

        if ($zip5 === '' || $plus4 === '') {
            return null;
        }

        return $this->dataset->localJurisdictions($state, $zip5, $plus4);
    }

    /**
     * The all-in rate for a resolved rooftop locality: EVERY applicable local
     * record summed, then the state share added per the state's {@see RateBasis}.
     * Summing is not optional — Kansas City's rate is the county's 1% plus the
     * city's 1.625% on top of the state's 6.5%, and taking either one alone is
     * wrong.
     *
     * ALL of them must resolve. If the boundary index names an authority the rate
     * records do not carry, the sum would be short by that authority's share and
     * still be labelled authoritative — an under-charge that looks certain. So a
     * partial match yields null and the caller falls back to the honest state rate.
     *
     * The per-authority shares are kept as {@see RateComponent}s on the returned
     * rate. Only this method knows them: once the percentages are summed the split
     * cannot be recovered downstream, and a per-jurisdiction remittance needs it.
     *
     * `$reducedStateShare`, when given, is a category the state taxes at a reduced
     * rate (groceries). It replaces the state share AND switches the local records
     * to their food/drug rate — the dataset carries `foodDrugRate` per locality for
     * exactly this, and reading `generalRate` there would charge a Tennessee
     * grocery basket the 2.75% general local rate where the food rate is 2.25%.
     *
     * @param  list<string>  $codes
     */
    private function stacked(string $state, array $codes, ?DateTimeImmutable $at, ?string $reducedStateShare = null): ?TaxRate
    {
        $basis = $this->dataset->rateBasis($state);
        $localPercent = BigDecimal::zero();

        /** @var list<RateComponent> $components */
        $components = [];

        foreach ($codes as $code) {
            $local = $this->activeComponent($state, $code, $at, $reducedStateShare !== null);

            if ($local === null) {
                return null;
            }

            $components[] = $local;
            $localPercent = $localPercent->plus($local->percentage);
        }

        // "No local record applies here" is only an all-in ANSWER on a
        // component-basis state, where the state share genuinely is the whole rate
        // (Indiana levies no local tax). On a combined-basis state the record IS
        // the all-in rate, so its absence leaves nothing all-in to report; with no
        // basis at all we do not know which case we are in. Either way, refusing
        // sends the caller to the honest state rate at Derived confidence instead
        // of stamping a bare — or zero — figure as an authoritative rooftop one.
        if ($components === [] && $basis !== RateBasis::Component) {
            return null;
        }

        // Combined records already include the state share; component records are
        // just the local addend and need the state rate added on top.
        if ($basis === RateBasis::Component) {
            // ON THE SUPPLY'S DATE, like everything else. Stacking today's state
            // share onto a historical local rate produces a percentage that was
            // never in force anywhere: Kansas at 5.5% through 2025 plus a 1% local
            // came out as 7.5% for a 2025 supply, because the state half had
            // already moved to 6.5%.
            $statePercent = $reducedStateShare ?? $this->dataset->stateRatePercent($state, $at);

            // On a component-basis state the local records are only the ADDEND. If
            // the state share cannot be read — a state missing from the baseline, a
            // section that would not load — then skipping it quietly returns the
            // locals alone and stamps them authoritative: Kansas City at 1.625%
            // instead of 9.125%, four fifths of the tax gone, on an answer that
            // claims to be a rooftop figure. Refusing sends the caller to the state
            // rate, which is null for the same reason, so the engine denies.
            if ($statePercent === null) {
                return null;
            }

            $localPercent = $localPercent->plus(BigDecimal::of($statePercent));
            // Coded with the state itself. An authority with no code cannot be
            // merged with the same authority on another line — a document-level
            // roll-up has to be able to tell "the state" apart from "some unnamed
            // district", and only a code does that.
            array_unshift($components, new RateComponent(JurisdictionLevel::State, $statePercent, $state));
        }

        if ($basis === RateBasis::Combined) {
            $components = $this->decomposeCombined($state, $components, $localPercent, $at, $reducedStateShare);
        }

        return new TaxRate(
            UsTaxDataset::normalize((string) $localPercent),
            $reducedStateShare !== null ? RateKind::Reduced : RateKind::Standard,
            self::SOURCE,
            Confidence::Authoritative,
            $components,
        );
    }

    /**
     * Split a combined-basis all-in rate into the state share and the local share.
     *
     * A combined record (California's CDTFA place rates) publishes one figure that
     * already contains the state share, so the per-authority split the record
     * itself suggests is not available — but the state share IS known exactly, and
     * subtracting it leaves the aggregate local share. That two-line split is the
     * one that drives remittance, so it is worth reporting.
     *
     * The remainder is deliberately levelled {@see JurisdictionLevel::Local}, not
     * the record's own level: it aggregates every district taxing that address, so
     * labelling it "city" and naming it after the record would attribute other
     * authorities' money to the city.
     *
     * Only decomposable from exactly ONE record: summing two all-in records would
     * double-count the state share, and the split would then be as wrong as the
     * total. Anything it cannot decompose yields no components.
     *
     * @param  list<RateComponent>  $components
     * @return list<RateComponent>
     */
    private function decomposeCombined(string $state, array $components, BigDecimal $allIn, ?DateTimeImmutable $at = null, ?string $reducedStateShare = null): array
    {
        if (count($components) !== 1) {
            return [];
        }

        // The supply's date here too: a combined record is decomposed by
        // subtracting the state share, and subtracting the wrong year's share
        // mis-attributes the split between the state and its localities.
        $statePercent = $reducedStateShare ?? $this->dataset->stateRatePercent($state, $at);

        if ($statePercent === null) {
            return [];
        }

        $stateShare = BigDecimal::of($statePercent);
        $localShare = $allIn->minus($stateShare);

        // An all-in rate below the state share means the record and the baseline
        // disagree; report no split rather than a negative local share.
        if ($localShare->isNegative()) {
            return [];
        }

        return [
            new RateComponent(JurisdictionLevel::State, $stateShare, $state),
            new RateComponent(
                JurisdictionLevel::Local,
                UsTaxDataset::normalize((string) $localShare),
                $components[0]->code,
                $components[0]->name,
            ),
        ];
    }

    /**
     * The state share, as the honest partial answer when no rooftop rate resolved.
     *
     * It refuses when the state share is ZERO and local authorities levy their own
     * tax — Alaska, which has no state sales tax but whose boroughs and cities do
     * (Juneau 5%, Wrangell 7%). There the state share is not a conservative
     * under-estimate a caller can reason about; it is an affirmative "no tax due"
     * on a supply that is taxed, and `Confidence::Derived` does not begin to say
     * so. Every other state's share is a real floor, so it is returned.
     */
    private function stateRate(string $state, ?DateTimeImmutable $at = null): ?TaxRate
    {
        $percent = $this->dataset->stateRatePercent($state, $at);

        if ($percent === null) {
            return null;
        }

        if (BigDecimal::of($percent)->isZero() && $this->dataset->hasLocalSalesTax($state)) {
            return null;
        }

        return new TaxRate($percent, RateKind::Standard, self::SOURCE, Confidence::Derived);
    }

    /**
     * One authority's applicable record as a {@see RateComponent}: the layer of
     * government it sits at, its share as a percentage, and the code and published
     * name the dataset carries for it.
     *
     * Selects the record whose effective window covers the date, and ONLY that.
     * There is no fallback to the first record in file order: a locality whose
     * expired record happens to be listed before its current one would otherwise
     * contribute the expired rate to a sum stamped `Authoritative`. Null when no
     * record applies — which is what makes {@see stacked()} refuse the whole stack
     * and fall back to the honest state rate.
     */
    private function activeComponent(string $state, string $code, ?DateTimeImmutable $at, bool $food = false): ?RateComponent
    {
        foreach ($this->dataset->localRateRecords($state, $code) as $record) {
            // A reduced-rate category reads the locality's FOOD rate, which the
            // dataset carries alongside the general one. They differ: a Tennessee
            // city may levy 2.75% generally and 2.25% on food, and several exempt
            // food locally altogether.
            $rate = $food ? ($record['foodDrugRate'] ?? null) : ($record['generalRate'] ?? null);

            if (! is_int($rate) && ! is_float($rate)) {
                continue;
            }

            $from = is_string($record['effectiveFrom'] ?? null) ? $record['effectiveFrom'] : null;
            $to = is_string($record['effectiveTo'] ?? null) ? $record['effectiveTo'] : null;

            if (! $this->covers($from, $to, $at)) {
                continue;
            }

            $name = $record['jurisdictionName'] ?? null;

            return new RateComponent(
                $this->levelOf($record),
                // Normalized like the total it will be summed into, so a consumer
                // never prints "1.62500%" of a "9.125%" rate.
                UsTaxDataset::normalize((string) BigDecimal::of((string) $rate)->multipliedBy(100)),
                $code,
                is_string($name) ? $name : null,
            );
        }

        return null;
    }

    /**
     * The layer of government a record belongs to. A record naming a level the
     * enum does not model falls back to the generic {@see JurisdictionLevel::Local}
     * — it is certainly a local authority (the state share never comes from here),
     * and the aggregate level says that without inventing a specific one.
     *
     * @param  array<array-key, mixed>  $record
     */
    private function levelOf(array $record): JurisdictionLevel
    {
        $level = $record['level'] ?? null;

        if (! is_string($level)) {
            return JurisdictionLevel::Local;
        }

        return JurisdictionLevel::tryFrom($level) ?? JurisdictionLevel::Local;
    }

    /**
     * Whether an effective window [from, to] covers the query date. A null `$at`
     * means TODAY.
     *
     * It used to mean "only an open-ended record qualifies", which sounded strict
     * and was in fact the opposite: the SST files close their current rows with a
     * sentinel end date (`2099-12-31`) rather than a null, so no record ever
     * matched and every lookup fell through to whatever was first in the file.
     * Treating null as today makes the window check actually run.
     */
    private function covers(?string $from, ?string $to, ?DateTimeImmutable $at): bool
    {
        $date = ($at ?? new DateTimeImmutable)->format('Y-m-d');

        return ($from === null || $from <= $date) && ($to === null || $date <= $to);
    }

    private function fractionToPercent(mixed $fraction): ?string
    {
        if (is_int($fraction) || is_float($fraction)) {
            $fraction = (string) $fraction;
        }

        if (! is_string($fraction) || ! is_numeric($fraction)) {
            return null;
        }

        return UsTaxDataset::normalize((string) BigDecimal::of($fraction)->multipliedBy(100));
    }
}
