<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Brick\Math\BigDecimal;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CategoryKeyedRateSource;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Contracts\FactAwareRateSource;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
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
final readonly class RegisterRateSource implements CategoryKeyedRateSource, CommodityRateSource, FactAwareRateSource
{
    private const string SOURCE = 'cbox-tax';

    public function __construct(
        private RegisterDataset $dataset,
        private RateResolver $resolver = new RateResolver,
        private LocalAuthorityResolver $authorities = new DefersLocalAuthorities,
        private DecisionFacts $facts = new DecisionFacts,
    ) {}

    public function withFacts(DecisionFacts $facts): static
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

        $carried = array_values(array_filter($candidates, fn (string $code): bool => $this->dataset->carries($code)));

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

            $stacked = $this->stacked($jurisdiction, $code, $rate, $key, $commodityCode, $at, $version) ?? $rate;

            return $this->withDeclined($this->withStatewideLocal($stacked, $code, $key, $at), [$code], $key, $at);
        }

        return null;
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
                $total->isZero() => RateKind::Zero,
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

            // Nobody resolved the address below the state line. The state share is
            // the honest answer, and saying so is what lets an operator see the gap.
            return $this->unstacked($state);
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
                Confidence::Authoritative,
                [],
                $state->limitedBy,
                $state->provenance,
            );
        }

        $total = BigDecimal::zero();
        $components = [];

        foreach ($authorities as $authority) {
            $isState = $authority === $this->stateOf($authority);

            // The state is IN the set, and it is the one member whose rate is a
            // standard band rather than a local record.
            $record = $isState
                ? null
                : $this->resolver->local($this->dataset->ratesFor($authority), $key, $at);

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

                $components[] = new RateComponent($this->levelOf($authority), $resolved->percentage, $authority, $this->nameOf($authority));
                $total = $total->plus($resolved->percentage);

                continue;
            }

            $percentage = $record['percentage'] ?? null;

            if (! is_string($percentage)) {
                return $this->unstacked($state);
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

                return new TaxRate(
                    $all,
                    $state->kind,
                    self::SOURCE,
                    Confidence::Authoritative,
                    $local->isZero() ? [] : [
                        new RateComponent(JurisdictionLevel::State, $state->percentage, $this->stateOf($authority)),
                        // Trailing zeros stripped: 10.75 − 7.25 is three and a half
                        // per cent, and 3.50 makes a scale artefact of the
                        // subtraction look like a statement about precision.
                        new RateComponent(JurisdictionLevel::Local, $local->strippedOfTrailingZeros(), $authority, $this->shortNameOf($authority)),
                    ],
                    null,
                    $state->provenance,
                );
            }

            $components[] = new RateComponent($this->levelOf($authority), $percentage, $authority, $this->nameOf($authority));
            $total = $total->plus(BigDecimal::of($percentage));
        }

        return new TaxRate(
            // 5.3 + 1.7 is seven per cent. Printing it as 7.0 makes a scale artefact
            // of the addition look like a statement about precision.
            $total->strippedOfTrailingZeros(),
            $state->kind,
            self::SOURCE,
            Confidence::Authoritative,
            $components,
            $state->limitedBy,
            $state->provenance,
        );
    }

    /**
     * The level from the code itself: `us:KS:COUNTY-209` is a county.
     *
     * A bare `us:KS` is the state, which is IN the resolved set — the boundary file
     * says whether the state's own rate applies there, and Nevada's says it does
     * not on every row.
     */
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

        if ($amount === null || Shape::text($unit['per'] ?? null) !== 'dollar') {
            return null;
        }

        return BigDecimal::of($amount)->multipliedBy(100)->strippedOfTrailingZeros()->__toString();
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

        $record = $this->resolver->local($this->dataset->ratesFor($code), $key, $at);

        if ($record === null || ($record['kind'] ?? null) !== 'local_component') {
            return $rate;
        }

        if (($record['category'] ?? null) === null && $rate->kind === RateKind::Zero) {
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

        return new TaxRate(
            $rate->percentage->plus($share)->strippedOfTrailingZeros(),
            $rate->kind,
            self::SOURCE,
            $rate->confidence,
            $components,
            $rate->limitedBy,
            $rate->provenance,
        );
    }

    /**
     * The state share, marked as the product of a resolution that did not complete.
     *
     * Both ways of failing land here and they must look the same to a caller: an
     * address nothing resolved, and an address that resolved to an authority this
     * store cannot price. Either way the figure is short, and the one thing that
     * must not happen is it being returned as though it were the whole rate.
     */
    private function unstacked(TaxRate $state): TaxRate
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
            $state->limitedBy ?? RateLimit::NoLocalResolution,
            $state->provenance,
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
     * The authority's own segment, for a component that names a place rather than a
     * key — `us:CA:CITY-ALAMEDA` reads as `ALAMEDA` on a return.
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

        foreach (array_keys($this->dataset->namesIn('us/'.$parts[1])) as $jurisdiction) {
            if (substr_count($jurisdiction, ':') > 1) {
                return true;
            }
        }

        return false;
    }

    /** The bare state code a local authority sits under. */
    private function stateOf(string $code): string
    {
        $parts = explode(':', $code);

        return $parts[0].':'.($parts[1] ?? '');
    }

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
            // Rounding the table to that figure disagrees by up to a cent on the
            // remainder, which is why it is FLAGGED. It used to be refused instead,
            // and refusing priced nothing at all in those four states — a rate within
            // a cent that says so is worth more to a shop than an exception.
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

        return new TaxRate(
            $percentage,
            $this->kind($rate),
            is_string($by) ? self::SOURCE.':'.$by : self::SOURCE,
            $resolved['inferred'] || $resolved['ambiguous'] || $bracketed || $resolved['narrowed'] ? Confidence::Derived : Confidence::Authoritative,
            [],
            $this->limit($resolved) ?? ($bracketed ? RateLimit::BracketSchedule : null),
            new RateProvenance(
                self::SOURCE,
                $version,
                Shape::text(Shape::map($effective)['from'] ?? null),
                Shape::text(Shape::map($provenance)['snapshot'] ?? null),
            ),
        );
    }

    /**
     * The register's band, in the three the engine models.
     *
     * `zero` and `exempt` are separate facts — zero-rating preserves the right to
     * deduct input tax and exemption removes it — but both are 0% to a price, and
     * the engine's {@see RateKind} carries the price side. The distinction survives
     * in the provenance rather than being lost.
     *
     * @param  array<string, mixed>  $rate
     */
    private function kind(array $rate): RateKind
    {
        return match ($rate['kind'] ?? null) {
            'standard', 'combined' => RateKind::Standard,
            'zero', 'exempt' => RateKind::Zero,
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
