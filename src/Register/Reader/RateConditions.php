<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

use Cbox\Tax\ValueObjects\DecisionFacts;
use Cbox\Tax\ValueObjects\UnsettledCondition;

/**
 * Whether a rate's published conditions let it apply to this supply.
 *
 * A CATEGORY FINDS THE NEIGHBOURHOOD; THE CONDITIONS DECIDE THE HOUSE. The United
 * Kingdom zero-rates agricultural inputs — but only seeds for food crops and live
 * animals of a kind used for food, and fertiliser is named nowhere. A seller who files
 * fertiliser under agricultural inputs has classified it correctly and is still owed a
 * 20% answer. Before this, the engine returned the zero rate for it, authoritative,
 * because it had been asked about exactly that category.
 *
 * Each condition is read from what the register typed: a predicate over named facts, a
 * list of tariff codes, or — where neither exists — only the statute's words.
 *
 * A PREDICATE BEING TRUE MEANS THE RATE APPLIES, FOR EVERY KIND. The register writes an
 * exclusion already negated: the United Kingdom's food zero rate "excludes ice cream"
 * as `not(product.isIceCreamOrSimilarFrozenProduct)`, which is true for bread. The kind
 * describes the clause; it does not flip the predicate. Reading `excludes` as "drop the
 * rate when true" inverted 549 conditions on 387 rates and charged the zero rate on
 * sweets while taxing bread — so every kind is read the same way: all true, the rate
 * applies; any false, it does not; otherwise it is unsettled.
 *
 * `set_by_instrument` and `unsettled` say the substance is somewhere the register has
 * not read, and are unknown whatever the facts.
 *
 * AN UNKNOWN CONDITION KEEPS THE RATE AND SAYS SO. It does not drop it — dropping would
 * move the price of every supply whose seller has not yet described its products, all
 * at once — and it does not bless it either. The answer is the published rate, flagged
 * with exactly what would settle it.
 */
class RateConditions
{
    public const string APPLIES = 'applies';

    public const string DOES_NOT_APPLY = 'does_not_apply';

    public const string UNSETTLED = 'unsettled';

    /** Kinds whose content lives somewhere the register has not read. */
    private const array UNREADABLE = ['set_by_instrument', 'unsettled'];

    /**
     * @param  array<string, mixed>  $rate
     * @return array{status: self::APPLIES|self::DOES_NOT_APPLY|self::UNSETTLED, unsettled: list<UnsettledCondition>}
     */
    public static function verdict(array $rate, DecisionFacts $facts): array
    {
        $unsettled = [];

        foreach (Shape::records($rate['conditions'] ?? null) as $condition) {
            $kind = Shape::text($condition['kind'] ?? null) ?? 'unsettled';
            $truth = in_array($kind, self::UNREADABLE, true) ? null : self::truth($kind, $condition, $facts);

            if ($truth === false) {
                return ['status' => self::DOES_NOT_APPLY, 'unsettled' => []];
            }

            if ($truth === null) {
                $unsettled[] = self::describe($kind, $condition);
            }
        }

        return [
            'status' => $unsettled === [] ? self::APPLIES : self::UNSETTLED,
            'unsettled' => $unsettled,
        ];
    }

    /**
     * Whether an unsettled condition is worth flagging on this answer.
     *
     * A QUALIFYING condition always is: "applies only to seeds and food animals"
     * narrows inside the category that was asked about, and an answer that ignores it
     * zero-rates fertiliser. An EXCLUSION at the rung that was asked about usually is
     * not: Ireland zero-rates books "but excluding newspapers", and a caller who asked
     * about a book is not asking about a newspaper — flagging it put a caveat on 123 of
     * the register's answers to buy a warning on 25. Reached by climbing, the same
     * exclusion is the whole question: sweets climb to the United Kingdom's food zero
     * rate, which excludes confectionery.
     *
     * An exclusion the seller answered in part is flagged at the exact rung too.
     *
     * A condition the facts SETTLE is never flagged either way — a false one has
     * already removed the rate before this is asked, and a true one is the rate's own
     * scope confirmed.
     */
    public static function worthFlagging(UnsettledCondition $condition, bool $exactRung, DecisionFacts $facts): bool
    {
        if ($condition->kind !== 'excludes' || ! $exactRung) {
            return true;
        }

        // UNLESS THE SELLER TOUCHED IT. An exclusion the seller said nothing about is
        // usually about something else; one it answered in part is about this product.
        // The UK's food zero rate excludes "confectionery, not including cakes or
        // biscuits": told a product IS confectionery and nothing about cakes, it is
        // still open — and staying quiet returned sweets at 0%, authoritative.
        return array_any($condition->facts, $facts->has(...));
    }

    /**
     * The facts a commodity code settles on its own: its CN code and the six-digit HS
     * heading every tariff in the world shares. A seller who classified a product for
     * customs has already answered every condition the register writes in codes.
     */
    public static function withCommodityCode(DecisionFacts $facts, ?string $commodityCode): DecisionFacts
    {
        if ($commodityCode === null || trim($commodityCode) === '') {
            return $facts;
        }

        $scheme = 'cn';
        $code = trim($commodityCode);

        if (str_contains($code, ':')) {
            [$scheme, $code] = explode(':', $code, 2);
            $scheme = strtolower(trim($scheme));
        }

        $digits = preg_replace('/[^0-9]/', '', $code) ?? '';

        if ($digits === '' || ! in_array($scheme, ['cn', 'hs'], true)) {
            return $facts;
        }

        if ($scheme === 'cn') {
            $facts = $facts->withDefault('classification.cnCode', $digits);
        }

        return strlen($digits) >= 6 ? $facts->withDefault('classification.hsCode', substr($digits, 0, 6)) : $facts;
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private static function truth(string $kind, array $condition, DecisionFacts $facts): ?bool
    {
        $predicate = $condition['predicate'] ?? null;

        if (is_array($predicate)) {
            return Predicate::evaluate(Shape::map($predicate), $facts);
        }

        // A bare code list: the older way of writing a condition in tariff terms. On a
        // qualifying clause the codes are the rate's scope, the same test a
        // `prefix_in` predicate makes. On an EXCLUSION the direction is not stated —
        // a list can name what is carved out or what is left in, and 28 such lists are
        // published with nothing to tell which — so it is not read at all rather than
        // read one way and risk inverting it. It stays unsettled until it is typed.
        $codes = self::codes($condition);

        if ($codes !== [] && $kind !== 'excludes') {
            return Predicate::evaluate(['fact' => 'classification.cnCode', 'op' => 'prefix_in', 'value' => $codes], $facts);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return list<string>
     */
    private static function codes(array $condition): array
    {
        $codes = [];

        foreach ((array) ($condition['codes'] ?? []) as $code) {
            if (is_string($code) && ($digits = preg_replace('/[^0-9]/', '', $code) ?? '') !== '') {
                $codes[] = $digits;
            }
        }

        return $codes;
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private static function describe(string $kind, array $condition): UnsettledCondition
    {
        $predicate = $condition['predicate'] ?? null;
        $facts = is_array($predicate) ? Predicate::facts(Shape::map($predicate)) : [];
        $byCode = $facts !== [] && array_any($facts, static fn (string $fact): bool => str_starts_with($fact, 'classification.'))
            || (! is_array($predicate) && $kind !== 'excludes' && self::codes($condition) !== []);

        return new UnsettledCondition(
            kind: $kind,
            says: Shape::text($condition['says'] ?? null) ?? '',
            names: Shape::text($condition['names'] ?? null),
            facts: array_values(array_filter($facts, static fn (string $fact): bool => ! str_starts_with($fact, 'classification.'))),
            settledByCommodityCode: $byCode,
            source: Shape::text($condition['source'] ?? null),
        );
    }
}
