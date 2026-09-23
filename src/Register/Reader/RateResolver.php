<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

use Cbox\Tax\ValueObjects\DecisionFacts;
use Cbox\Tax\ValueObjects\UnsettledCondition;
use DateTimeImmutable;

/**
 * Picks the one rate that applies, out of everything a jurisdiction publishes.
 *
 * Four rules decide it, and every one of them was measured against the register
 * rather than assumed:
 *
 * **Containment, not a null end date.** 4 863 live rates end `2078-12-31` — the
 * Streamlined matrix's way of writing "no end", carried faithfully because it is
 * what the member states filed. A reader taking `until === null` as shorthand for
 * "current" silently drops them, Oklahoma's own state rate among them.
 *
 * **The customer bears it, or it is not part of this price.** A digital services
 * tax is a levy on the supplier's turnover. Summed into a cart it overcharges the
 * customer and under-declares the liability in one step, so only
 * `borneBy === 'customer'` is ever considered.
 *
 * **The classification is the key, and the longest one wins.** 93% of live EU rates
 * carry a CN, CPA or band code, and on `(jurisdiction, category, classification)`
 * there is no ambiguity anywhere in the register — the apparent ambiguity at
 * `(jurisdiction, category)` is entirely an artefact of collapsing across it. Codes
 * run to two, four, six and eight digits, and a chapter can disagree with a
 * subheading beneath it: 95 live cases where a shorter code and a longer one under
 * it carry different rates in one country. So a match found by shortening the code
 * is an INFERENCE and is marked as one.
 *
 * **Never the lowest of several.** Where a rung genuinely has more than one answer,
 * the band is refused and the standard rate applies. That is the direction a
 * customer can be refunded from; the other one is found at audit.
 */
final readonly class RateResolver
{
    /**
     * The kinds that are not bands: a component added to the band, and an all-in
     * total that replaces it. Both are read through {@see self::local()}.
     */
    private const array SHARES = ['local_component', 'combined'];

    /**
     * @param  list<array<string, mixed>>  $rates
     * @return array{rate: array<string, mixed>, inferred: bool, ambiguous: bool, narrowed: bool, by?: ?string, unsettled?: list<UnsettledCondition>}|null
     */
    public function resolve(array $rates, string $category, ?string $commodityCode, ?DateTimeImmutable $at = null, ?DecisionFacts $facts = null): ?array
    {
        $on = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $facts = RateConditions::withCommodityCode($facts ?? new DecisionFacts, $commodityCode);

        // CONDITIONS FILTER BEFORE ANYTHING IS CHOSEN. A row whose conditions say it
        // does not reach this supply is not a candidate at all — not a competing
        // answer to be disambiguated, not a rate to fall back from. Removing it here
        // is what lets the ladder carry on to the answer that does apply: fertiliser
        // filed under agricultural inputs climbs past the zero rate for seeds and
        // lands on the standard band.
        $live = array_values(array_filter(
            $this->live($rates, $on),
            static fn (array $rate): bool => RateConditions::verdict($rate, $facts)['status'] !== RateConditions::DOES_NOT_APPLY,
        ));

        if ($live === []) {
            return null;
        }

        if ($commodityCode !== null && trim($commodityCode) !== '') {
            // SCOPED TO THE CATEGORY LADDER. A code REFINES the question that was
            // asked; it never moves it somewhere else. Searched across every live
            // rate, a customs code scoped to foodstuffs answered a question about
            // hotel accommodation — a wrong rate wearing the shape of a precise one.
            $within = array_values(array_filter($live, static function (array $rate) use ($category): bool {
                $its = $rate['category'] ?? null;

                return $its === null || in_array($its, CategoryMap::ladder($category), true);
            }));

            $byCode = $this->byClassification($within, $commodityCode);

            if ($byCode !== null) {
                // A code is narrower than any category: the rung it answers is exact.
                return $this->settled($byCode, $facts, exactRung: true);
            }
        }

        foreach (CategoryMap::ladder($category) as $rung) {
            // Bands only. A local share is ADDED to whatever the band answers and is
            // read by {@see self::local()}; counting it here made Virginia — which
            // exempts groceries while its localities levy 1% on them anyway — look
            // like a state answering its own question twice, and every Virginia
            // grocery resolved to the standard rate, flagged.
            $atRung = array_values(array_filter(
                $live,
                static fn (array $r): bool => ($r['category'] ?? null) === $rung
                    && ! in_array($r['kind'] ?? null, self::SHARES, true),
            ));

            if ($atRung === []) {
                continue;
            }

            // A BARE ROW IS THE CATEGORY'S GENERAL ANSWER. Where a rung carries one
            // row with no classification and any number with one, the bare row is
            // what the category itself is rated at and the classified rows are
            // narrower cases beneath it — Title IX exempts medical care in every
            // member state, and Germany, Greece, Cyprus, Czechia and Portugal each
            // publish one CPA-coded reduced rate under that exemption. Reading the
            // pair as a disagreement charged the standard rate for a doctor.
            //
            // Only when there is exactly one. Two bare rows at one rung genuinely
            // are two answers, which is what the grocery and prescription defects
            // were, and they must keep falling through.
            $bare = array_values(array_filter(
                $atRung,
                static fn (array $r): bool => ($r['classification'] ?? null) === null,
            ));

            if (count($bare) === 1) {
                return $this->settled(['rate' => $bare[0], 'inferred' => false, 'ambiguous' => false, 'narrowed' => false], $facts, $rung === $category);
            }

            $distinct = $this->distinct($atRung);

            if (count($distinct) === 1) {
                // Climbing the category tree is not an inference and is not flagged.
                // The tree is hierarchical by design: France publishes its book rate
                // at `goods.publications`, and that IS the published answer for a
                // book. Marking every such resolution "derived" put a caveat on most
                // answers in the register, and a caveat on everything is a caveat
                // nobody reads. Only a SHORTENED COMMODITY CODE is an inference,
                // because there the register demonstrably disagrees with itself
                // between one length and the next.
                return $this->settled(['rate' => $atRung[0], 'inferred' => false, 'ambiguous' => false, 'narrowed' => false], $facts, $rung === $category);
            }

            // More than one live answer at the rung the item actually is. Climbing
            // further would only widen the question, so stop and take the standard
            // rate, flagged — the caller closes this by supplying a commodity code.
            $standard = $this->standard($live);

            return $standard === null ? null : $this->settled(['rate' => $standard, 'inferred' => false, 'ambiguous' => true, 'narrowed' => false], $facts, false);
        }

        $standard = $this->standard($live);

        return $standard === null ? null : $this->settled(['rate' => $standard, 'inferred' => false, 'ambiguous' => false, 'narrowed' => false], $facts, false);
    }

    /**
     * Attach what the chosen rate's conditions left open.
     *
     * A QUALIFYING CONDITION AT EVERY RUNG. This used to be asked only when the answer
     * came from a broader category than the one asked about, on the reasoning that a
     * caller who named the exact category had classified the product. They had
     * classified it into the NEIGHBOURHOOD: the United Kingdom's zero rate for
     * agricultural inputs reaches only seeds and food animals, and a seller who filed
     * fertiliser there got 0%, authoritative. An exclusion at the exact rung is still
     * left alone — see {@see RateConditions::worthFlagging()}.
     *
     * @param  array{rate: array<string, mixed>, inferred: bool, ambiguous: bool, narrowed: bool, by?: ?string}  $answer
     * @return array{rate: array<string, mixed>, inferred: bool, ambiguous: bool, narrowed: bool, by?: ?string, unsettled: list<UnsettledCondition>}
     */
    private function settled(array $answer, DecisionFacts $facts, bool $exactRung): array
    {
        $unsettled = array_values(array_filter(
            RateConditions::verdict($answer['rate'], $facts)['unsettled'],
            static fn (UnsettledCondition $condition): bool => RateConditions::worthFlagging($condition, $exactRung),
        ));

        return [...$answer, 'narrowed' => $unsettled !== [], 'unsettled' => $unsettled];
    }

    /**
     * The live LOCAL record for a jurisdiction: a component to add to the state
     * share, or an all-in combined total that replaces it.
     *
     * A local record is not category-scoped the way a band is — in most states it
     * carries no category at all, and that untyped record is the general local rate.
     * Where one IS categorised it wins, matched up the category ladder rather than on
     * exact equality, because the ordinance files at the rung it names and the
     * invoice sells at a leaf below it. That is how Tennessee's reduced local food
     * rate is reached from `goods.food.basic`.
     *
     * @param  list<array<string, mixed>>  $rates
     * @return array<string, mixed>|null
     */
    public function local(array $rates, ?string $category = null, ?DateTimeImmutable $at = null): ?array
    {
        $on = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $live = $this->live($rates, $on);

        // THE SAME LADDER `resolve()` CLIMBS, for the same reason. A local record is
        // filed at whatever rung the ordinance names, and that is hardly ever the
        // leaf a catalogue sells at: 11 322 live local records sit on `goods.food`
        // while the item on the invoice is `goods.food.basic`. Matching only on exact
        // equality reached none of them and fell through to the untyped general rate
        // — which is the city's FULL rate, not its reduced grocery one, so the error
        // ran in the over-charging direction and looked plausible on every receipt.
        //
        // Nearest rung wins, so a record on the leaf still beats one on its parent.
        $ladder = $category === null ? [] : CategoryMap::ladder($category);
        $best = null;
        $bestRung = PHP_INT_MAX;
        $fallback = null;

        foreach ($live as $rate) {
            if (! in_array($rate['kind'] ?? null, ['local_component', 'combined'], true)) {
                continue;
            }

            $its = $rate['category'] ?? null;

            if ($its === null) {
                $fallback ??= $rate;

                continue;
            }

            $rung = array_search($its, $ladder, true);

            if ($rung !== false && $rung < $bestRung) {
                $best = $rate;
                $bestRung = $rung;
            }
        }

        return $best ?? $fallback;
    }

    /**
     * The best classification match: exact if published, otherwise the longest code
     * that is a prefix of the one asked for.
     *
     * @param  list<array<string, mixed>>  $live
     * @return array{rate: array<string, mixed>, inferred: bool, ambiguous: bool, narrowed: bool, by?: ?string}|null
     */
    private function byClassification(array $live, string $code): ?array
    {
        [$scheme, $wanted] = $this->readCode($code);

        if ($wanted === '') {
            return null;
        }

        $best = null;
        $bestLength = -1;

        foreach ($live as $rate) {
            $classification = $rate['classification'] ?? null;

            if (! is_array($classification)) {
                continue;
            }

            $published = $classification['code'] ?? null;
            $itsScheme = $classification['scheme'] ?? null;

            if (! is_string($published) || $published === '' || ! is_string($itsScheme)) {
                continue;
            }

            // THE SCHEME IS PART OF THE KEY. `32` is a CPA division and a CN
            // chapter, and they are about different things — letting one answer for
            // the other is a wrong rate that looks like a precise one.
            if (strtolower($itsScheme) !== $scheme) {
                continue;
            }

            $candidate = $this->pack($published);

            if (! str_starts_with($wanted, $candidate)) {
                continue;
            }

            if (strlen($candidate) > $bestLength) {
                $best = $rate;
                $bestLength = strlen($candidate);
            }
        }

        if ($best === null) {
            return null;
        }

        $classification = $best['classification'] ?? null;
        $answered = is_array($classification)
            ? Shape::scalar($classification['scheme'] ?? null).':'.Shape::scalar($classification['code'] ?? null)
            : null;

        return [
            'rate' => $best,
            'inferred' => $bestLength < strlen($wanted),
            'narrowed' => false,
            'ambiguous' => false,
            // Which code decided it, so a reader can see WHY this rate and not the
            // heading's. Two codes under one category give two different right
            // answers, and the assessment should say which one it took.
            'by' => $answered,
        ];
    }

    /**
     * A commodity code as `[scheme, packed code]`.
     *
     * A bare code is assumed to be CN: that is the vocabulary a tariff line is
     * written in, and the one almost every classified rate in the register is
     * scoped by.
     *
     * @return array{0: string, 1: string}
     */
    private function readCode(string $code): array
    {
        $code = trim($code);
        $scheme = 'cn';

        if (str_contains($code, ':')) {
            [$scheme, $code] = explode(':', $code, 2);
            $scheme = strtolower(trim($scheme));
        }

        return [$scheme, $this->pack($code)];
    }

    /** The tariff prints `0102 21 10`; the register files `01022110`. */
    private function pack(string $code): string
    {
        return strtoupper(str_replace([' ', '.', '-'], '', trim($code)));
    }

    /**
     * The rates in force on a date that a customer can actually be charged.
     *
     * TWO FIELDS, NOT ONE. `borneBy` says whose liability it is and `mayBePassedOn`
     * says whether the statute lets the seller recover it on the invoice, and only
     * the pair decides anything. A digital services tax is the case worth excluding:
     * a levy on the supplier's turnover that may NOT be passed on, and summing it
     * into a cart overcharges the customer and under-declares the liability at once.
     *
     * Reading `borneBy` alone excluded far more than that. A transaction privilege
     * tax, a general excise tax and a gross receipts tax are all legally the
     * seller's and all are passed on as a matter of course — it is why a Honolulu
     * receipt shows 4.712%. Filtering them out left Arizona, Hawaii, New Mexico,
     * Guam, the US Virgin Islands, Malaysia, Aruba and Curaçao with no rate at all,
     * so the engine refused on every supply into eight jurisdictions. There is not
     * one row in the register today that this filter was meant to catch: all
     * nineteen supplier-borne rows may be passed on.
     *
     * @param  list<array<string, mixed>>  $rates
     * @return list<array<string, mixed>>
     */
    private function live(array $rates, string $on): array
    {
        return array_values(array_filter($rates, static function (array $rate) use ($on): bool {
            if (($rate['borneBy'] ?? null) !== 'customer' && ($rate['mayBePassedOn'] ?? null) !== true) {
                return false;
            }

            $effective = $rate['effective'] ?? null;
            $from = is_array($effective) ? ($effective['from'] ?? null) : null;
            $until = is_array($effective) ? ($effective['until'] ?? null) : null;

            if (is_string($from) && $from > $on) {
                return false;
            }

            return ! (is_string($until) && $until < $on);
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $rates
     * @return list<string>
     */
    private function distinct(array $rates): array
    {
        $seen = [];

        foreach ($rates as $rate) {
            $seen[$this->measure($rate)] = true;
        }

        return array_keys($seen);
    }

    /**
     * What a row charges, which is not the same as which band it is.
     *
     * THE KIND IS NOT PART OF THE ANSWER TO A PRICE. Four states file groceries at a
     * 0% standard rate and exempt at once, and Tennessee at 4% reduced and 4%
     * standard; keying distinctness on the kind as well made each of those a
     * disagreement and charged the standard rate for food. Zero-rating and exemption
     * differ in whether input tax may be deducted — a real difference, carried in
     * the kind on the row that is returned — but they charge the customer the same,
     * and this method exists to decide what to charge.
     *
     * The register draws the line in the same place, in its own `one-answer-per-
     * category` check: "a 0% standard rate and a 0% exemption are different facts
     * about deduction and the same answer about what to charge".
     *
     * @param  array<string, mixed>  $rate
     */
    private function measure(array $rate): string
    {
        $perUnit = $rate['perUnit'] ?? null;
        $brackets = $rate['brackets'] ?? null;

        if (is_array($perUnit)) {
            return 'unit:'.json_encode($perUnit);
        }

        if (is_array($brackets)) {
            return 'brackets:'.json_encode($brackets);
        }

        return 'percentage:'.Shape::scalar($rate['percentage'] ?? null);
    }

    /**
     * The jurisdiction's own headline rate: the standard band, which carries no
     * category because it is what applies when no band does.
     *
     * @param  list<array<string, mixed>>  $rates
     * @return array<string, mixed>|null
     */
    private function standard(array $rates): ?array
    {
        // THE BAND CARRIES NO CATEGORY, which is the whole of what makes it the
        // headline rate — and taking the first `standard` row without checking meant
        // a category-scoped one could stand in for it. Arizona files a per-unit
        // standard rate on telecommunications ahead of its 5.6% band; that row has no
        // percentage at all, so the state resolved to nothing and the engine refused
        // on every Arizona supply.
        foreach ($rates as $rate) {
            if (($rate['kind'] ?? null) === 'standard' && ($rate['category'] ?? null) === null) {
                return $rate;
            }
        }

        // A COMBINED ROW IS AN ALL-IN STANDARD RATE for the place it names. Where a
        // jurisdiction is addressed directly rather than stacked under one — Ontario's
        // harmonised 13%, California's per-place total — that row is the answer, and
        // refusing it sent the caller up to the federal share alone.
        foreach ($rates as $rate) {
            if (($rate['kind'] ?? null) === 'combined' && ($rate['category'] ?? null) === null) {
                return $rate;
            }
        }

        // A STATE THAT LEVIES NO SALES TAX AT ALL says so with a single untyped row
        // at 0% exempt. Delaware, Montana, New Hampshire and Oregon each publish
        // exactly one, and nothing else. Refusing it turned the register's clearest
        // possible answer — there is no tax here — into `UnresolvedTaxRate` on every
        // line a shop sold into those four states, with the remedy naming a rate
        // source to go and configure for a tax that does not exist.
        //
        // It is checked LAST so that a jurisdiction carrying both a standard band and
        // an exempt row still answers with the band.
        foreach ($rates as $rate) {
            if (in_array($rate['kind'] ?? null, ['zero', 'exempt'], true) && ($rate['category'] ?? null) === null) {
                return $rate;
            }
        }

        // A jurisdiction whose only records are local components has no standard
        // band of its own — the state above it does. Null, so the caller stacks.
        return null;
    }
}
