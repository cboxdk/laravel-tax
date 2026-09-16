<?php

declare(strict_types=1);

namespace Cbox\Tax\Cadastre\Reader;

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
            $atRung = array_values(array_filter($live, static fn (array $r): bool => ($r['category'] ?? null) === $rung));

            if ($atRung === []) {
                continue;
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
            $seen[Shape::scalar($rate['percentage'] ?? null).'/'.Shape::scalar($rate['kind'] ?? null)] = true;
        }

        return array_keys($seen);
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
