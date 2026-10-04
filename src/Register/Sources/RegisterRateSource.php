<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CategoryKeyedRateSource;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Contracts\FactAwareRateSource;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use Cbox\Tax\Contracts\ReportsDistrictOverlays;
use Cbox\Tax\Contracts\ReportsSplitPostcodes;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Exceptions\DatasetNotInstalled;
use Cbox\Tax\RateSource\DefersLocalAuthorities;
use Cbox\Tax\Register\Reader\CategoryMap;
use Cbox\Tax\Register\Reader\RateResolver;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\BracketRow;
use Cbox\Tax\ValueObjects\BracketSchedule;
use Cbox\Tax\ValueObjects\CombinedTaxTable;
use Cbox\Tax\ValueObjects\DecisionFacts;
use Cbox\Tax\ValueObjects\RateComponent;
use Cbox\Tax\ValueObjects\RateProvenance;
use Cbox\Tax\ValueObjects\TaxRate;
use Cbox\Tax\ValueObjects\UnsettledCondition;
use DateTimeImmutable;

/**
 * Rates out of the compiled register — the only rate source this package ships.
 *
 * It reads local files. There is no request here and no cache TTL, because there is
 * nothing to expire: the store holds one pinned release, and which release is live
 * changes when somebody runs `tax:data:sync`, not on a timer. A rate cannot move
 * under a half-priced order.
 *
 * WHAT IT REFUSES, AND WHY EACH IS DIFFERENT. Nothing installed is a refusal naming
 * the command that fixes it. A regime the store was not compiled with is a refusal
 * too — a store built `--region=eu` knows nothing about Japan, and answering "no tax
 * in Japan" from an absence would be a fabrication. Only a jurisdiction the store
 * DOES carry and has no rate for returns null, which is the honest "this source
 * cannot answer" the chain is built on.
 */
readonly class RegisterRateSource implements CategoryKeyedRateSource, CommodityRateSource, FactAwareRateSource
{
    private const string SOURCE = 'cbox-tax';

    public function __construct(
        private RegisterDataset $dataset,
        private RateResolver $resolver = new RateResolver,
        private LocalAuthorityResolver $authorities = new DefersLocalAuthorities,
        private DecisionFacts $facts = new DecisionFacts,
    ) {}

    public function withFacts(DecisionFacts $facts): self
    {
        return new self($this->dataset, $this->resolver, $this->authorities, $facts);
    }

    /**
     * The published conditions a rate for this supply depends on that the facts given
     * cannot settle — the question a catalogue asks when a seller opens a market.
     *
     * Empty when the answer is settled, including when no condition was published at
     * all. Read against the same ladder, the same facts and the same commodity code the
     * rate itself would be, so it describes the answer a checkout would actually get.
     *
     * @return list<UnsettledCondition>
     */
    public function unsettledConditions(
        Jurisdiction $jurisdiction,
        string $key,
        ?string $commodityCode = null,
        ?DateTimeImmutable $at = null,
    ): array {
        $unsettled = [];

        foreach ($this->candidates($jurisdiction, $at) as $code) {
            if (! $this->dataset->carries($code)) {
                continue;
            }

            $resolved = $this->resolver->resolve($this->dataset->ratesFor($code), $key, $commodityCode, $at, $this->facts);

            if ($resolved === null) {
                continue;
            }

            array_push($unsettled, ...($resolved['unsettled'] ?? []));

            // The province answers alongside the country in Canada; everywhere else the
            // first place that answers is the answer.
            if ($jurisdiction->subdivision === null || $jurisdiction->country->value === 'US') {
                break;
            }
        }

        // AND THE LOCAL SHARES. A locality can file its rate on a condition too —
        // Illinois' Metro East districts on whether the retailer is liable for the
        // district tax — and what settles it belongs on the same list.
        if ($jurisdiction->country->value === 'US' && $jurisdiction->subdivision !== null) {
            $state = UsCode::of($jurisdiction->subdivision);

            foreach ([$state, ...($this->authorities->authoritiesFor($jurisdiction, $at) ?? [])] as $code) {
                $answer = $this->resolver->localAnswer($this->dataset->ratesFor($code), $key, $at, $this->taxedAsGeneralAt($this->stateOf($code), $key, $at), $this->facts);
                array_push($unsettled, ...($answer['unsettled'] ?? []));
            }
        }

        return $unsettled;
    }

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        return $this->rateForCommodity($jurisdiction, $category, null, $at);
    }

    public function rateForCommodity(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?string $commodityCode,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        return $this->rateAt($jurisdiction, CategoryMap::keyFor($category), $commodityCode, $at);
    }

    public function rateForKey(
        Jurisdiction $jurisdiction,
        string $categoryKey,
        ?string $commodityCode = null,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        $this->dataset->assertCategoryPublished($categoryKey);

        return $this->rateAt($jurisdiction, $categoryKey, $commodityCode, $at);
    }

    private function rateAt(
        Jurisdiction $jurisdiction,
        string $key,
        ?string $commodityCode,
        ?DateTimeImmutable $at,
    ): ?TaxRate {
        $version = $this->dataset->requireVersion();
        $candidates = $this->candidates($jurisdiction, $at);

        if ($candidates === []) {
            return null;
        }

        $carried = array_values(array_filter($candidates, $this->dataset->carries(...)));

        if ($carried === []) {
            // Every place this country could be is in a regime nobody compiled. The
            // store cannot answer, and pretending otherwise is the failure this
            // whole design exists to avoid.
            throw new DatasetNotInstalled($this->dataset->storeRoot());
        }

        $composed = $this->composed($jurisdiction, $candidates, $carried, $key, $commodityCode, $at, $version);

        if ($composed !== null) {
            return $this->withDeclined($composed, array_slice($carried, 0, 2), $key, $at);
        }

        foreach ($carried as $code) {
            $rate = $this->resolve($code, $key, $commodityCode, $at, $version);

            if ($rate === null) {
                continue;
            }

            // A total the state files for the category replaces its share and every
            // local one, so there is nothing to stack and no local to miss.
            $replaced = $this->replacesTheStack($code, $key, $commodityCode, $at) !== null;
            $stacked = $replaced ? $rate : ($this->stacked($jurisdiction, $code, $rate, $key, $commodityCode, $at, $version) ?? $rate);

            // A SPLIT ZIP IS ONE OF ITS ANSWERS, NOT THIS ADDRESS'S. The resolver
            // returns the set a bare five-digit ZIP falls in first; where the ZIP holds
            // several, that total is plausible and not necessarily right, so it is
            // flagged with the step that settles it — the ZIP+4, or the street.
            if ($this->authorities instanceof ReportsSplitPostcodes && $this->authorities->spansSeveralSets($jurisdiction, $at)) {
                $stacked = $stacked->qualifiedBy(RateLimit::PostcodeSpansLocalities);
            }

            // A DISTRICT DRAWN OVER THIS ZIP, NOT TESTED. Answered from the postal key
            // alone, the address was priced as though it were outside every district —
            // right outside, wrong inside. Flagged only where a district would change the
            // figure: Nebraska's Good Life Districts at 5.5% stand in place of a 5.5% state
            // rate, and a caveat there would send somebody looking for nothing.
            if ($this->authorities instanceof ReportsDistrictOverlays) {
                foreach ($this->authorities->districtsUnchecked($jurisdiction, $at) as $district) {
                    if ($this->districtChangesRate($district, $key, $commodityCode, $at, $version)) {
                        $stacked = $stacked->qualifiedBy(RateLimit::DistrictNeedsPoint);

                        break;
                    }
                }
            }

            return $this->withDeclined($replaced ? $stacked : $this->withStatewideLocal($stacked, $code, $key, $at), [$code], $key, $at);
        }

        return null;
    }

    /**
     * Whether a district would price differently from what it stands in place of.
     *
     * True where anything is unknown — an unreadable district layer, a rate this
     * store cannot resolve — because "it would not have mattered" is a claim, and one
     * nothing here can make.
     *
     * @param  array{authority: ?string, replaces: list<string>}  $district
     */
    private function districtChangesRate(array $district, string $key, ?string $commodityCode, ?DateTimeImmutable $at, string $version): bool
    {
        if ($district['authority'] === null) {
            return true;
        }

        $inside = $this->resolve($district['authority'], $key, $commodityCode, $at, $version);

        if ($inside === null) {
            return true;
        }

        $outside = BigDecimal::zero();

        foreach ($district['replaces'] as $code) {
            $replaced = $this->resolve($code, $key, $commodityCode, $at, $version);

            if ($replaced === null) {
                return true;
            }

            $outside = $outside->plus($replaced->percentage);
        }

        return ! $inside->percentage->isEqualTo($outside);
    }

    /**
     * Flag a rate where the law names a different one for this category and the
     * register declined to file it.
     *
     * Only a declined rate filed AT or ABOVE the rung asked about: one declined for
     * hotel stays says nothing about a question asked about services in general, and
     * flagging it there would put a caveat on every service in the country. One
     * declined without any category is skipped for the same reason — the Congo's
     * "certain basic necessities, a set the DGI names and never lists" could be any
     * product at all. An answer already carrying a limit keeps it; the first gap
     * named is the one to close.
     *
     * @param  list<string>  $codes
     */
    private function withDeclined(TaxRate $rate, array $codes, string $key, ?DateTimeImmutable $at): TaxRate
    {
        if ($rate->limitedBy !== null) {
            return $rate;
        }

        $ladder = CategoryMap::ladder($key);

        foreach ($codes as $code) {
            foreach ($this->dataset->rulesOn($code, 'declined_rate', $at ?? new DateTimeImmutable('today')) as $rule) {
                $payload = Shape::map($rule['payload'] ?? null);
                $category = Shape::text($payload['category'] ?? null);
                $declined = Shape::text($payload['rate'] ?? null);

                if ($category === null || ! in_array($category, $ladder, true)) {
                    continue;
                }

                if ($declined !== null && is_numeric($declined) && BigDecimal::of($declined)->isEqualTo($rate->percentage)) {
                    continue;
                }

                return new TaxRate(
                    $rate->percentage,
                    $rate->kind,
                    $rate->source,
                    Confidence::Derived,
                    $rate->components,
                    RateLimit::RateDeclined,
                    $rate->provenance,
                );
            }
        }

        return $rate;
    }

    /**
     * A province's tax on top of the country's, where the register carries both.
     *
     * Canada files like the United States — a federal rate and a provincial share —
     * except that here the federal rate exists, on `ca:CA`, so the province is not
     * the whole answer and neither is the country. Reading only the first one that
     * answered charged Alberta nothing (its single row says it levies no provincial
     * tax), British Columbia the federal 5% without its 7% PST, and an Ontario doctor
     * the full 13% HST on a supply the federal act exempts.
     *
     * The province's share decides how the two meet:
     *
     *  - `local_component` (a PST) is ADDED to the federal rate, each side answering
     *    for the category on its own: a book in Quebec is 5% federal and 0% QST.
     *  - `combined` (an HST) REPLACES the federal rate, because it already includes
     *    it — unless the federal act zero-rates or exempts the category, which the
     *    harmonised tax follows. A provincial category row still wins over both.
     *  - no share at all means the province adds nothing, whatever its own rows say,
     *    so the federal answer is the whole rate.
     *
     * Null hands back to the ordinary most-specific-first read: no subdivision was
     * asked, the country or the province is not carried, the country has no answer,
     * or the province files a band of its own rather than a share of the country's.
     *
     * @param  list<string>  $candidates
     * @param  list<string>  $carried
     */
    private function composed(
        Jurisdiction $jurisdiction,
        array $candidates,
        array $carried,
        string $key,
        ?string $commodityCode,
        ?DateTimeImmutable $at,
        string $version,
    ): ?TaxRate {
        if ($jurisdiction->subdivision === null || count($carried) < 2 || $carried[0] !== $candidates[0]) {
            return null;
        }

        [$province, $country] = [$carried[0], $carried[1]];
        $federal = $this->resolve($country, $key, $commodityCode, $at, $version);

        if ($federal === null) {
            return null;
        }

        $records = $this->dataset->ratesFor($province);
        $own = $this->resolver->resolve($records, $key, $commodityCode, $at, $this->facts);
        $ownRow = $own['rate'] ?? null;
        $ownScoped = $ownRow !== null && (($ownRow['category'] ?? null) !== null || ($ownRow['classification'] ?? null) !== null);
        $share = $this->resolver->local($records, $key, $at);

        if (($share['kind'] ?? null) === 'combined') {
            if ($ownScoped) {
                return null;
            }

            $federalRow = $this->resolver->resolve($this->dataset->ratesFor($country), $key, $commodityCode, $at, $this->facts)['rate'] ?? null;
            $federalScoped = $federalRow !== null && (($federalRow['category'] ?? null) !== null || ($federalRow['classification'] ?? null) !== null);

            return $federalScoped && $federal->percentage->isZero() ? $federal : null;
        }

        if (($share['kind'] ?? null) !== 'local_component') {
            // No share: the province adds nothing. Its own untyped row — Alberta's
            // single 0% exempt — says exactly that, and a band of its own would be a
            // different shape of register this read does not assume.
            return $ownRow === null || $ownScoped || in_array($ownRow['kind'] ?? null, ['zero', 'exempt'], true)
                ? $federal
                : null;
        }

        $provincial = $ownScoped ? $this->resolve($province, $key, $commodityCode, $at, $version) : null;
        $part = $provincial->percentage ?? (is_string($share['percentage'] ?? null) ? BigDecimal::of($share['percentage']) : null);

        if ($part === null) {
            return $this->unstacked($federal);
        }

        $total = $federal->percentage->plus($part);
        $derived = $federal->confidence !== Confidence::Authoritative || ($provincial !== null && $provincial->confidence !== Confidence::Authoritative);

        return new TaxRate(
            $total->strippedOfTrailingZeros(),
            match (true) {
                // Nothing due at either level: the federal band says which kind of
                // nothing. An exempt GST supply is exempt from the HST built on it.
                $total->isZero() => $federal->kind === RateKind::Exempt ? RateKind::Exempt : RateKind::Zero,
                $federal->percentage->isZero() => RateKind::Standard,
                default => $federal->kind,
            },
            self::SOURCE,
            $derived ? Confidence::Derived : Confidence::Authoritative,
            [
                new RateComponent(JurisdictionLevel::Country, $federal->percentage, $country, $this->nameOf($country)),
                new RateComponent(JurisdictionLevel::State, $part->strippedOfTrailingZeros(), $province, $this->nameOf($province)),
            ],
            $federal->limitedBy ?? $provincial?->limitedBy,
            $federal->provenance,
        );
    }

    /**
     * Add the local authorities that tax this address to the state share.
     *
     * EVERY AUTHORITY THAT APPLIES, or none of them. Inside Kansas City a county and
     * a city both levy — 6.5 + 1.0 + 1.625 — and a rate that stopped at the first
     * one would be an under-charge stamped authoritative, which is the outcome this
     * package works hardest to prevent. So a local record the store cannot price
     * abandons the whole stack and returns the state share at lower confidence
     * rather than a total that is short by one authority's share.
     *
     * A `combined` record is already an all-in total — California files them that
     * way — so it REPLACES the state share instead of adding to it. Adding it would
     * charge California's own portion twice.
     */
    private function stacked(
        Jurisdiction $jurisdiction,
        string $code,
        TaxRate $state,
        string $key,
        ?string $commodityCode,
        ?DateTimeImmutable $at,
        string $version,
    ): ?TaxRate {
        // ASKED EVEN WITH NO LOCALITY ON THE JURISDICTION. Colorado is the shape
        // this seam exists for: several authorities at one address, and nothing
        // shipped that can resolve the state below the state line, so there is no
        // locality to carry. Gating the question on one would mean the resolver a
        // host bound for exactly that state is never consulted — and the assessment
        // still comes out with a plausible number, just the state share.
        $authorities = $this->authorities->authoritiesFor($jurisdiction, $at);

        if ($authorities === null) {
            // Nobody resolved below the state line. Whether that is worth FLAGGING
            // depends on what was asked: a caller who supplied an address wanted an
            // address-level answer and did not get one, while a caller who supplied
            // only a state got exactly what they asked for. Flagging both would put
            // a caveat on every state-level assessment, and a caveat on everything
            // is one nobody reads.
            if ($jurisdiction->locality === null) {
                // A US STATE SHARE IS RARELY THE WHOLE RATE — locals apply almost
                // everywhere — so it is Derived and it is FLAGGED. The remedy is
                // real and the operator's to act on: sync the state's boundary
                // index, or bind a resolver. Outside the US a country rate is the
                // whole answer and carries no caveat.
                // ...unless the state HAS no locals to miss. Delaware, Montana, New
                // Hampshire and Oregon levy no sales tax at all, and four more carry
                // no sub-state authority; there the state share is the whole rate and
                // a caveat would send somebody looking for something that is not
                // missing.
                return $jurisdiction->country->value === 'US' && $this->hasLocals($code)
                    ? $this->unstacked($state)
                    : null;
            }

            // AN ADDRESS IN A STATE WITH NO LOCALS has nothing below the line to resolve
            // either. Oregon asked with a ZIP came back flagged NoLocalResolution — a
            // remedy nobody can act on, on every sale into a state with no sales tax.
            if (! $this->hasLocals($code)) {
                return null;
            }

            // Nobody resolved the address below the state line. The state share is
            // the honest answer, and saying so is what lets an operator see the gap.
            // A SPLIT ZIP ASKED BARE is a gap of a different kind: the index is
            // installed and knows the ZIP, but files no answer for the five digits
            // alone — Illinois lists single add-ons — so the remedy is the ZIP+4, not
            // a sync.
            return $this->unstacked(
                $state,
                $this->authorities instanceof ReportsSplitPostcodes && $this->authorities->spansSeveralSets($jurisdiction, $at)
                    ? RateLimit::PostcodeSpansLocalities
                    : RateLimit::NoLocalResolution,
            );
        }

        if ($authorities === []) {
            // A POSITIVE FINDING: a row covers this address and no local authority
            // levies there, so the state share is the whole rate. That is a different
            // claim from "we only managed to find the state share", and collapsing
            // the two would either understate a certainty or overstate a guess.
            return new TaxRate(
                $state->percentage,
                $state->kind,
                self::SOURCE,
                // The state share is the whole rate, and exactly as sure as it is — a
                // state that writes its tax as a table is priced by the table.
                $state->confidence,
                [],
                $state->limitedBy,
                $state->provenance,
                $state->schedule,
            );
        }

        $total = BigDecimal::zero();
        // Whether a local share was chosen on a condition nobody settled.
        $open = false;
        $components = [];
        $inPlaceOfState = null;
        $replacedState = null;
        // The tables of the local shares that publish one, and how many local shares
        // there are: only when every local share has its own table is the total one.
        $localTables = [];
        $localShares = 0;

        foreach ($authorities as $authority) {
            $isState = $authority === $this->stateOf($authority);

            // The state is IN the set, and it is the one member whose rate is a
            // standard band rather than a local record.
            $answer = $isState
                ? null
                : $this->resolver->localAnswer($this->dataset->ratesFor($authority), $key, $at, $this->taxedAsGeneralAt($this->stateOf($authority), $key, $at), $this->facts);
            $record = $answer['rate'] ?? null;
            $open = $open || ($answer['unsettled'] ?? []) !== [];

            if ($record === null) {
                $resolved = $isState
                    ? $state
                    : $this->resolve($authority, $key, $commodityCode, $at, $version);

                if ($resolved === null) {
                    // One authority this store cannot price abandons the WHOLE stack.
                    // A total short by one share is an under-charge stamped
                    // authoritative, and nobody audits a plausible number.
                    return $this->unstacked($state);
                }

                if (! $isState && $resolved->kind === RateKind::Standard) {
                    // A SUB-STATE CODE FILED AS `standard` IS THE STATE'S OWN RATE
                    // THERE. Nebraska's Good Life Districts are: inside Avenue One in
                    // Omaha the state rate is 2.75%, not 5.5%, and the city's 1.5% is
                    // still due on top. Summed with the state share it came to 9.75%,
                    // authoritative, where 4.25% is due. Two such codes in one set
                    // would be two state rates for one address, which is not a
                    // number to pick between.
                    if ($inPlaceOfState !== null) {
                        return $this->unstacked($state);
                    }

                    $inPlaceOfState = new RateComponent(JurisdictionLevel::State, $resolved->percentage, $authority, $this->nameOf($authority));
                    $replacedState = $this->stateOf($authority);

                    continue;
                }

                $components[] = new RateComponent($this->levelOf($authority), $resolved->percentage, $authority, $this->nameOf($authority));
                $total = $total->plus($resolved->percentage);

                continue;
            }

            $percentage = $record['percentage'] ?? null;
            $localShares++;

            // A LOCAL SHARE WRITTEN AS ITS OWN TABLE. Pennsylvania's 1% local tax is
            // computed on its own table — ten cents on each exact $10 — and added to the
            // state's, which is not the same as one 7% figure.
            if (! is_string($percentage)) {
                $percentage = $this->perWholeUnit($record);
                $table = $this->schedule($record);

                if ($percentage === null || $table === null) {
                    return $this->unstacked($state);
                }

                $localTables[] = $table;
            }

            if (($record['kind'] ?? null) === 'combined') {
                // An all-in figure: it IS the whole rate, and adding the state share
                // to it would charge California's own portion twice.
                //
                // It still DECOMPOSES, because a return has to be filed against
                // authorities rather than against a total. The state share is known
                // exactly, so the remainder is the aggregate of every district
                // taxing there — levelled `local`, never attributed to the named
                // city, because the register does not say how that remainder splits
                // and inventing a split would be a filing nobody can defend.
                $all = BigDecimal::of($percentage);
                $local = $all->minus($state->percentage);

                return $this->openIf($open, new TaxRate(
                    $all,
                    $state->kind,
                    self::SOURCE,
                    Confidence::Authoritative,
                    $local->isZero() ? [] : [
                        new RateComponent(JurisdictionLevel::State, $state->percentage, $this->stateOf($authority), $this->nameOf($this->stateOf($authority))),
                        // Trailing zeros stripped: 10.75 − 7.25 is three and a half
                        // per cent, and 3.50 makes a scale artefact of the
                        // subtraction look like a statement about precision.
                        // Named as the register names it. The code's own segment is a
                        // place only in California (`CITY-ALAMEDA`); in Illinois it is
                        // IDOR's location number, and a return line reading
                        // "016-0001-1" is not one anybody can file from.
                        new RateComponent(JurisdictionLevel::Local, $local->strippedOfTrailingZeros(), $authority, $this->nameOf($authority) ?? $this->shortNameOf($authority)),
                    ],
                    null,
                    $state->provenance,
                ));
            }

            $components[] = new RateComponent($this->levelOf($authority), $percentage, $authority, $this->nameOf($authority));
            $total = $total->plus(BigDecimal::of($percentage));
        }

        if ($inPlaceOfState !== null) {
            // The district's rate stands where the state's would; everything else in
            // the set is still owed beside it.
            $replaced = false;

            foreach ($components as $i => $component) {
                if ($component->code === $replacedState) {
                    $total = $total->minus($component->percentage);
                    $components[$i] = $inPlaceOfState;
                    $replaced = true;
                }
            }

            if (! $replaced) {
                // The state line reads first in a breakdown, whoever levies it.
                array_unshift($components, $inPlaceOfState);
            }

            $total = $total->plus($inPlaceOfState->percentage);
        }

        // A TABLE PLUS A PERCENTAGE IS NEITHER. Pennsylvania's 6% is a table; Allegheny
        // adds 1% and Philadelphia 2%, and the state publishes its own tables for those
        // totals, which the register does not carry. The total is the table's per-dollar
        // figure plus the local share — right to within a cent, and flagged as that.
        $tabled = $state->schedule !== null && $inPlaceOfState === null && $localShares > 0 && count($localTables) === $localShares;
        $approximated = $state->schedule !== null && $inPlaceOfState === null && ! $tabled;

        return $this->openIf($open, new TaxRate(
            // 5.3 + 1.7 is seven per cent. Printing it as 7.0 makes a scale artefact
            // of the addition look like a statement about precision.
            $total->strippedOfTrailingZeros(),
            $state->kind,
            self::SOURCE,
            // NO SURER THAN WHAT IT STANDS ON. Every local share resolved, so the stack
            // is complete — but an approximated table, or an assumed taxability, makes
            // the total exactly that sure. It used to call itself authoritative while
            // carrying the state's flag.
            $approximated && $state->confidence === Confidence::Authoritative ? Confidence::Derived : $state->confidence,
            $components,
            $state->limitedBy ?? ($approximated ? RateLimit::BracketSchedule : null),
            $state->provenance,
            // Every share a table: the total is the tables read one by one and added.
            $tabled ? new CombinedTaxTable([$state->schedule, ...$localTables]) : null,
        ));
    }

    /**
     * The per-dollar rate a bracket schedule works out to above one whole unit, as a
     * percentage.
     *
     * `{"amount": "0.06", "currency": "USD", "per": "dollar"}` is six per cent. Only
     * a per-DOLLAR figure converts: a schedule expressed per litre or per item is a
     * different kind of tax and has no percentage to give.
     *
     * @param  array<string, mixed>  $rate
     */
    private function perWholeUnit(array $rate): ?string
    {
        $unit = Shape::map(Shape::map(Shape::map($rate['brackets'] ?? null)['above'] ?? null)['perWholeUnit'] ?? null);
        $amount = Shape::text($unit['amount'] ?? null);

        if ($amount === null || ! is_numeric($amount) || Shape::text($unit['per'] ?? null) !== 'dollar') {
            return null;
        }

        // `every` is how many dollars one whole unit is: Pennsylvania's local tax is ten
        // cents on each exact $10, which is one per cent.
        $every = $this->every($unit);

        return $every === null ? null : BigDecimal::of($amount)->multipliedBy(100)->dividedBy($every, 10, RoundingMode::HalfUp)->strippedOfTrailingZeros()->__toString();
    }

    /**
     * How many dollars one whole unit of a table is: one, unless the table says
     * otherwise. Null for a size that is not a positive number.
     *
     * @param  array<array-key, mixed>  $unit
     */
    private function every(array $unit): ?BigDecimal
    {
        $every = Shape::text($unit['every'] ?? null) ?? '1';

        return is_numeric($every) && BigDecimal::of($every)->isPositive() ? BigDecimal::of($every) : null;
    }

    /**
     * Whether a live `price_exemption` rule caps this category's exemption.
     *
     * Matched up the category ladder, as everything category-keyed in this source
     * is: the rule is filed at the rung the statute names and the invoice sells at a
     * leaf below it.
     */
    private function cappedByPrice(string $code, string $key, ?DateTimeImmutable $at): bool
    {
        $on = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $ladder = CategoryMap::ladder($key);

        foreach ($this->dataset->rulesFor($code, 'price_exemption') as $rule) {
            $effective = Shape::map($rule['effective'] ?? null);
            $from = Shape::text($effective['from'] ?? null);
            $until = Shape::text($effective['until'] ?? null);

            if (($from !== null && $from > $on) || ($until !== null && $until < $on)) {
                continue;
            }

            $category = Shape::text(Shape::map($rule['payload'] ?? null)['category'] ?? null);

            if ($category !== null && in_array($category, $ladder, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A LOCAL SHARE THAT APPLIES STATEWIDE: a `local_component` filed under the bare
     * state code rather than under any authority inside the state.
     *
     * Virginia is the live example — 1% on groceries, levied across the whole state,
     * so there is no city or county to file it against. It is the whole local story
     * for that category, and the register files it against the state itself.
     *
     * APPLIED ON EVERY PATH, and that is the correction this carries. Put inside the
     * stacking loop it only ran where a boundary file resolved an authority set —
     * and Virginia, the one state it was written for, is not a Streamlined member and
     * publishes no boundary file at all. It never ran once in production. A share
     * that applies everywhere in a state by statute needs no address resolved to
     * reach it, which is exactly why it must not sit behind one.
     *
     * Only `local_component` qualifies. A `combined` record is an all-in total that
     * REPLACES the state share — Virginia files one of those too, for general goods —
     * and adding to it would charge the band twice.
     */
    private function withStatewideLocal(TaxRate $rate, string $code, string $key, ?DateTimeImmutable $at): TaxRate
    {
        if ($code !== $this->stateOf($code)) {
            return $rate;
        }

        $answer = $this->resolver->localAnswer($this->dataset->ratesFor($code), $key, $at, $this->taxedAsGeneralAt($code, $key, $at), $this->facts);
        $record = $answer['rate'] ?? null;

        if ($record === null || ($record['kind'] ?? null) !== 'local_component') {
            return $rate;
        }

        if (($record['category'] ?? null) === null && $rate->kind->isNil()) {
            // AN UNTYPED SHARE FOLLOWS THE CATEGORY IT IS ADDED TO. Brazil files a
            // 0.1% IBS component with no category — the general local share — beside
            // zero-rated rows for basic food, books and newspapers, and adding it to
            // those billed 0.1% on a loaf of bread the statute exempts.
            //
            // Virginia is the case this must NOT swallow: its 1% is filed AT
            // `goods.food`, next to the state's own 0% exemption on the same
            // category. Naming the category is the register saying the locality
            // levies there whatever the state does, and that one still applies.
            return $rate;
        }

        $percentage = $record['percentage'] ?? null;

        if (! is_string($percentage) || BigDecimal::of($percentage)->isZero()) {
            return $rate;
        }

        $share = BigDecimal::of($percentage);
        $components = $rate->components;

        foreach ($components as $component) {
            if ($component->code === $code && $component->level === JurisdictionLevel::Local) {
                return $rate;
            }
        }

        // An incomplete breakdown stays incomplete: where the stack could not be
        // resolved the component list is deliberately empty, and one entry would
        // read as the whole of it.
        if ($components !== []) {
            $components[] = new RateComponent(JurisdictionLevel::Local, $share, $code, $this->nameOf($code));
        }

        // A table with a share added is no longer the table: priced by the sum, flagged.
        $approximated = $rate->schedule !== null;

        return $this->openIf(($answer['unsettled'] ?? []) !== [], new TaxRate(
            $rate->percentage->plus($share)->strippedOfTrailingZeros(),
            $rate->kind,
            self::SOURCE,
            $approximated && $rate->confidence === Confidence::Authoritative ? Confidence::Derived : $rate->confidence,
            $components,
            $rate->limitedBy ?? ($approximated ? RateLimit::BracketSchedule : null),
            $rate->provenance,
        ));
    }

    /**
     * The state share, marked as the product of a resolution that did not complete.
     *
     * Both ways of failing land here and they must look the same to a caller: an
     * address nothing resolved, and an address that resolved to an authority this
     * store cannot price. Either way the figure is short, and the one thing that
     * must not happen is it being returned as though it were the whole rate.
     */
    private function unstacked(TaxRate $state, RateLimit $gap = RateLimit::NoLocalResolution): TaxRate
    {
        return new TaxRate(
            $state->percentage,
            $state->kind,
            self::SOURCE,
            Confidence::Derived,
            [],
            // THE FIRST GAP NAMED IS THE ONE TO CLOSE. A rate that already carries a
            // limit — an unsettled condition, an ambiguous heading, a bracket
            // schedule — keeps it: "resolve the address" settles none of those, and
            // overwriting them sent a seller to fix its geocoding when what was open
            // was the product. The confidence is Derived either way.
            $state->limitedBy ?? $gap,
            $state->provenance,
            $state->schedule,
        );
    }

    /**
     * The authority's published name, for a breakdown somebody has to file from.
     *
     * A remittance line reading `us:FL:COUNTY-ALACHUA` is not one anybody can take
     * to a Department of Revenue.
     */
    private function nameOf(string $code): ?string
    {
        $jurisdiction = $this->dataset->jurisdiction($code);

        return $jurisdiction === null ? null : Shape::text($jurisdiction['name'] ?? null);
    }

    /**
     * The authority's own segment — `us:CA:CITY-ALAMEDA` reads as `ALAMEDA` — for a
     * release that publishes no name for it.
     */
    private function shortNameOf(string $code): ?string
    {
        $segment = explode(':', $code)[2] ?? null;

        if ($segment === null) {
            return null;
        }

        return str_contains($segment, '-') ? substr($segment, (int) strpos($segment, '-') + 1) : $segment;
    }

    /**
     * Whether the register carries any authority BELOW this state.
     *
     * Read off the jurisdictions the store holds rather than asserted, so a state
     * that adopts a local tax stops being a special case the day the register says
     * so.
     */
    private function hasLocals(string $code): bool
    {
        $parts = explode(':', $code);

        if ($parts[0] !== 'us' || ! isset($parts[1])) {
            return false;
        }

        return array_any(array_keys($this->dataset->namesIn('us/'.$parts[1])), fn (string $jurisdiction): bool => substr_count($jurisdiction, ':') > 1);
    }

    /** The bare state code a local authority sits under. */
    /**
     * The state's categorised all-in total for the key, where it files one; see
     * {@see RateResolver::combinedFor()}. Only a US state: elsewhere `combined` is a
     * province's harmonised share, which {@see self::composed()} reads.
     *
     * @return array<string, mixed>|null
     */
    private function replacesTheStack(string $code, string $key, ?string $commodityCode, ?DateTimeImmutable $at): ?array
    {
        if (! str_starts_with($code, 'us:') || $code !== $this->stateOf($code)) {
            return null;
        }

        $combined = $this->resolver->combinedFor($this->dataset->ratesFor($code), $key, $commodityCode, $at, $this->facts);

        return is_string($combined['percentage'] ?? null) ? $combined : null;
    }

    /** See {@see RateResolver::taxedAsGeneralAt()}; never for the general rate itself. */
    private function taxedAsGeneralAt(string $state, string $key, ?DateTimeImmutable $at): ?string
    {
        return $key === CategoryMap::FALLBACK ? null : $this->resolver->taxedAsGeneralAt($this->dataset->ratesFor($state), $key, null, $at, $this->facts);
    }

    /** A rate whose local share was chosen on an open condition, flagged as one. */
    private function openIf(bool $open, TaxRate $rate): TaxRate
    {
        return $open ? $rate->qualifiedBy(RateLimit::ConditionsUnevaluated) : $rate;
    }

    private function stateOf(string $code): string
    {
        $parts = explode(':', $code);

        return $parts[0].':'.($parts[1] ?? '');
    }

    /**
     * The level from the code itself: `us:KS:COUNTY-209` is a county.
     *
     * A bare `us:KS` is the state, which is IN the resolved set — the boundary file
     * says whether the state's own rate applies there, and Nevada's says it does
     * not on every row.
     */
    private function levelOf(string $code): JurisdictionLevel
    {
        $parts = explode(':', $code);

        if (count($parts) < 3) {
            return JurisdictionLevel::State;
        }

        return match (strtok($parts[2], '-')) {
            'COUNTY' => JurisdictionLevel::County,
            'CITY' => JurisdictionLevel::City,
            'DISTRICT' => JurisdictionLevel::SpecialDistrict,
            default => JurisdictionLevel::Local,
        };
    }

    /**
     * Where to look, most specific first.
     *
     * A SUB-FEDERAL COUNTRY IS ADDRESSED BY ITS SUBDIVISION, not by itself. The
     * register carries `us:KS`, never a `us:US` — there is no federal sales tax for
     * one to hold — so resolving the United States by country code finds nothing at
     * all, which reads exactly like a country that levies no tax.
     *
     * @return list<string>
     */
    private function candidates(Jurisdiction $jurisdiction, ?DateTimeImmutable $at): array
    {
        $codes = $this->dataset->codesForCountry($jurisdiction->country->value, $at);

        if ($jurisdiction->subdivision === null) {
            return $codes;
        }

        $subdivision = $jurisdiction->subdivision->value;
        $prefix = strtolower(substr($subdivision, 0, 2));
        $regional = $prefix.':'.substr($subdivision, 3);

        return [$regional, ...$codes];
    }

    private function resolve(string $code, string $key, ?string $commodityCode, ?DateTimeImmutable $at, string $version): ?TaxRate
    {
        $records = $this->dataset->ratesFor($code);

        if ($records === []) {
            return null;
        }

        $resolved = $this->resolver->resolve($records, $key, $commodityCode, $at, $this->facts);

        if ($resolved === null) {
            return null;
        }

        $rate = $resolved['rate'];
        $combined = $this->replacesTheStack($code, $key, $commodityCode, $at);

        if ($combined !== null) {
            $resolved = ['rate' => $combined, 'inferred' => false, 'ambiguous' => false, 'narrowed' => false];
            $rate = $combined;
        }

        if (in_array($rate['kind'] ?? null, ['zero', 'exempt'], true) && $this->cappedByPrice($code, $key, $at)) {
            // A PRICE-CAPPED EXEMPTION IS NOT A RATE OF ZERO. Massachusetts files
            // clothing two ways at once: a `goods.clothing` row at 0% exempt, and a
            // `price_exemption` rule capping that exemption at $175 with the excess
            // taxable. Read alone the row says clothing is free; read together they
            // say clothing is free UP TO $175.
            //
            // By the time a rate is asked for, the cap has already been applied —
            // below it the supply is exempt and no rate is consulted at all, so the
            // only question left is what the part ABOVE the cap is taxed at. It is
            // taxed at the standard rate. Answering 0% billed nothing on a $200 coat
            // in Massachusetts, New York and Rhode Island alike: every live
            // `price_exemption` rule in the register sits behind an exempt row.
            $standard = $this->resolver->resolve($records, CategoryMap::FALLBACK, null, $at);

            if ($standard !== null && is_string($standard['rate']['percentage'] ?? null)) {
                $resolved = $standard;
                $rate = $standard['rate'];
            }
        }

        $percentage = $rate['percentage'] ?? null;
        $bracketed = false;

        if (! is_string($percentage)) {
            // A BRACKET SCHEDULE CARRIES ITS OWN PER-DOLLAR RATE, and that rate is
            // the answer for a price. Alabama, Idaho, Maryland and Pennsylvania each
            // publish a table in cents instead of a percentage — 11 to 17 cents is
            // one cent of tax, 18 to 34 is two — with `above.perWholeUnit` giving
            // what applies past one unit: $0.06 per dollar, which is six per cent.
            //
            // The TABLE is carried with it and prices the sale; the per-dollar figure
            // is what the rate shows, and what prices an amount the table cannot — a
            // tax-inclusive price, a local share stacked on top. Only there is the
            // answer flagged, because only there is it within a cent rather than exact.
            // It used to be flagged everywhere, and before that refused, which priced
            // nothing at all in those four states.
            $percentage = $this->perWholeUnit($rate);

            if ($percentage === null) {
                // A per-unit amount with no percentage equivalent: a real published
                // rate this source cannot express. Not a number to invent.
                return null;
            }

            $bracketed = true;
        }

        $effective = $rate['effective'] ?? null;
        $provenance = $rate['provenance'] ?? null;

        $by = $resolved['by'] ?? null;
        $schedule = $bracketed ? $this->schedule($rate) : null;
        // A table that cannot be read is still a figure within a cent, and says so.
        $approximated = $bracketed && $schedule === null;

        return new TaxRate(
            $percentage,
            $this->kind($rate),
            is_string($by) ? self::SOURCE.':'.$by : self::SOURCE,
            $resolved['inferred'] || $resolved['ambiguous'] || $approximated || $resolved['narrowed'] ? Confidence::Derived : Confidence::Authoritative,
            [],
            $this->limit($resolved) ?? ($approximated ? RateLimit::BracketSchedule : null),
            new RateProvenance(
                self::SOURCE,
                $version,
                Shape::text(Shape::map($effective)['from'] ?? null),
                Shape::text(Shape::map($provenance)['snapshot'] ?? null),
            ),
            $schedule,
        );
    }

    /**
     * The published table, read into rows — null where any part of it cannot be read,
     * because a table missing a row prices some amounts wrong and says nothing.
     *
     * @param  array<string, mixed>  $rate
     */
    private function schedule(array $rate): ?BracketSchedule
    {
        $brackets = Shape::map($rate['brackets'] ?? null);
        $above = Shape::map($brackets['above'] ?? null);
        $unit = Shape::map($above['perWholeUnit'] ?? null);
        $currency = Shape::text($unit['currency'] ?? null) ?? 'USD';
        $rows = $this->bracketRows($brackets['rows'] ?? null);
        $aboveRows = $this->bracketRows($above['rows'] ?? null);
        $perUnit = Shape::text($unit['amount'] ?? null);

        $every = $this->every($unit);

        if ($rows === null || $rows === [] || $aboveRows === null || $every === null || ($unit !== [] && Shape::text($unit['per'] ?? null) !== 'dollar')) {
            return null;
        }

        return new BracketSchedule(
            $currency,
            $rows,
            $perUnit === null || ! is_numeric($perUnit) ? null : BigDecimal::of($perUnit),
            $aboveRows,
            $every,
        );
    }

    /** @return list<BracketRow>|null */
    private function bracketRows(mixed $published): ?array
    {
        $rows = [];

        foreach (Shape::records($published) as $row) {
            $from = Shape::text($row['from'] ?? null);
            $upTo = Shape::text($row['upTo'] ?? null);
            $tax = Shape::text($row['tax'] ?? null);

            if (! is_numeric($from) || ! is_numeric($upTo) || ! is_numeric($tax)) {
                return null;
            }

            $rows[] = new BracketRow(BigDecimal::of($from), BigDecimal::of($upTo), BigDecimal::of($tax));
        }

        return $rows;
    }

    /**
     * The register's band, in the four the engine models.
     *
     * `zero` and `exempt` are separate facts — zero-rating preserves the right to
     * deduct input tax and exemption removes it — and both are kept. They were once
     * folded into one on the grounds that both are 0% to a price, and every exempt
     * supply then went on the return as zero-rated.
     *
     * @param  array<string, mixed>  $rate
     */
    private function kind(array $rate): RateKind
    {
        return match ($rate['kind'] ?? null) {
            'standard', 'combined' => RateKind::Standard,
            'zero' => RateKind::Zero,
            'exempt' => RateKind::Exempt,
            'increased' => RateKind::Increased,
            default => RateKind::Reduced,
        };
    }

    /**
     * @param  array{rate: array<string, mixed>, inferred: bool, ambiguous: bool, narrowed: bool, by?: ?string, unsettled?: list<UnsettledCondition>}  $resolved
     */
    private function limit(array $resolved): ?RateLimit
    {
        if ($resolved['ambiguous']) {
            return RateLimit::HeadingAmbiguous;
        }

        if ($resolved['inferred']) {
            return RateLimit::ClassificationInferred;
        }

        return $resolved['narrowed'] ? RateLimit::ConditionsUnevaluated : null;
    }
}
