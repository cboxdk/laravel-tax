<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

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
     * @return array{rate: array<string, mixed>, inferred: bool, ambiguous: bool}|null
     */
    public function resolve(array $rates, string $category, ?string $commodityCode, ?DateTimeImmutable $at = null): ?array
    {
        $on = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $live = $this->live($rates, $on);

        if ($live === []) {
            return null;
        }

        if ($commodityCode !== null) {
            $byCode = $this->byClassification($live, $commodityCode);

            if ($byCode !== null) {
                return $byCode;
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
                return ['rate' => $bare[0], 'inferred' => false, 'ambiguous' => false];
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
                return ['rate' => $atRung[0], 'inferred' => false, 'ambiguous' => false];
            }

            // More than one live answer at the rung the item actually is. Climbing
            // further would only widen the question, so stop and take the standard
            // rate, flagged — the caller closes this by supplying a commodity code.
            $standard = $this->standard($live);

            return $standard === null ? null : ['rate' => $standard, 'inferred' => false, 'ambiguous' => true];
        }

        $standard = $this->standard($live);

        return $standard === null ? null : ['rate' => $standard, 'inferred' => false, 'ambiguous' => false];
    }

    /**
     * The live LOCAL record for a jurisdiction: a component to add to the state
     * share, or an all-in combined total that replaces it.
     *
     * Read directly rather than through the category ladder, because a local record
     * is not category-scoped in the way a band is — it carries no category at all in
     * most states. A category-matched one still wins where it exists, which is how
     * Tennessee's reduced local food rate is reached.
     *
     * @param  list<array<string, mixed>>  $rates
     * @return array<string, mixed>|null
     */
    public function local(array $rates, ?string $category = null, ?DateTimeImmutable $at = null): ?array
    {
        $on = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $live = $this->live($rates, $on);
        $fallback = null;

        foreach ($live as $rate) {
            if (! in_array($rate['kind'] ?? null, ['local_component', 'combined'], true)) {
                continue;
            }

            $its = $rate['category'] ?? null;

            if ($category !== null && $its === $category) {
                return $rate;
            }

            if ($its === null && $fallback === null) {
                $fallback = $rate;
            }
        }

        return $fallback;
    }

    /**
     * The best classification match: exact if published, otherwise the longest code
     * that is a prefix of the one asked for.
     *
     * @param  list<array<string, mixed>>  $live
     * @return array{rate: array<string, mixed>, inferred: bool, ambiguous: bool}|null
     */
    private function byClassification(array $live, string $code): ?array
    {
        $normalised = strtoupper(str_replace([' ', '.', '-'], '', $code));
        $best = null;
        $bestLength = -1;

        foreach ($live as $rate) {
            $classification = $rate['classification'] ?? null;
            $published = is_array($classification) ? ($classification['code'] ?? null) : null;

            if (! is_string($published) || $published === '') {
                continue;
            }

            $candidate = strtoupper(str_replace([' ', '.', '-'], '', $published));

            if (! str_starts_with($normalised, $candidate)) {
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

        return [
            'rate' => $best,
            'inferred' => $bestLength < strlen($normalised),
            'ambiguous' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rates
     * @return list<array<string, mixed>>
     */
    private function live(array $rates, string $on): array
    {
        return array_values(array_filter($rates, static function (array $rate) use ($on): bool {
            if (($rate['borneBy'] ?? null) !== 'customer') {
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
        foreach ($rates as $rate) {
            if (($rate['kind'] ?? null) === 'standard') {
                return $rate;
            }
        }

        // A jurisdiction whose only records are local components has no standard
        // band of its own — the state above it does. Null, so the caller stacks.
        return null;
    }
}
