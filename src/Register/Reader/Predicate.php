<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Cbox\Tax\Contracts\TaxRegister;
use Cbox\Tax\ValueObjects\DecisionFacts;

/**
 * Evaluates one of the register's typed predicates — the machine-readable half of a
 * condition on a rate — against the facts of a supply.
 *
 * The grammar is the register's own, read in full:
 *
 *  - a boolean, enum or string fact compared with `eq` or `in`;
 *  - a numeric fact compared with `eq`, `exceeds`, `at_least`, `below` or `at_most`,
 *    its value a decimal string so a threshold is never a float;
 *  - a classification fact — `classification.cnCode`, `classification.hsCode` and
 *    the other tariff and activity schemes — tested with `prefix_in` against a list
 *    of code prefixes, because a statute names a heading and a product carries the
 *    subheading beneath it;
 *  - `all`, `any` and `not` over other predicates;
 *  - `unsettled`: a limb the register could not read, typed so it can never answer
 *    yes.
 *
 * THREE-VALUED. A fact nobody supplied is unknown, not false — "the seller did not
 * say this is confectionery" is not "it is not confectionery". `all` is false if any
 * part is false and unknown if none is false but one is unknown; `any` is true if any
 * part is true and unknown if none is true but one is unknown; `not` inverts true and
 * false and leaves unknown alone.
 *
 * UNKNOWN RATHER THAN A REFUSAL on anything outside the grammar. A published decision
 * that cannot be read refuses, because it decides a treatment and there is nothing to
 * price without it. A condition on a rate is different: the rate itself is still a
 * published figure, and one unreadable clause should mark that answer as unsettled,
 * not stop every sale into the country.
 *
 * @internal Changes with the register's format. Applications ask the register through
 *           {@see TaxRegister}.
 */
class Predicate
{
    /** @var list<string> */
    private const array NUMERIC_OPS = ['exceeds', 'at_least', 'below', 'at_most'];

    /**
     * True, false, or null for unknown.
     *
     * @param  array<string, mixed>  $predicate
     */
    public static function evaluate(array $predicate, DecisionFacts $facts): ?bool
    {
        return self::test($predicate, $facts, 0);
    }

    /**
     * Every fact a predicate can ask for, so an unsettled answer can say what would
     * settle it — once, for all of them, rather than one per attempt.
     *
     * @param  array<string, mixed>  $predicate
     * @return list<string>
     */
    public static function facts(array $predicate): array
    {
        $names = [];
        self::collect($predicate, $names, 0);
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** Fact namespaces that describe the buyer's side of a sale. */
    private const array BUYER = ['recipient', 'use', 'evidence'];

    /** A path to true that stands on what the seller can state. */
    private const int FREE = 0;

    /** A path that needs only limbs the register could not read. */
    private const int UNREAD = 1;

    /** Every path needs a fact about the buyer. */
    private const int BUYER_BOUND = 2;

    /** No path: already the opposite of what is wanted. */
    private const int DEAD = 3;

    /**
     * Whether every way a predicate that is not yet settled could still come true
     * needs a fact about the buyer that has not been stated.
     *
     * Read through the structure, not a flat list of its facts: `all` needs each part,
     * so one part waiting on the buyer holds the whole; `any` needs one part, so it
     * waits on the buyer only if no part stands on the seller's side. A limb the
     * register could not read decides nothing either way: it offers the seller no path
     * (Texas exempts gas for residential use or on a limb nobody can read — that waits
     * on the buyer), and it holds nothing back beside a part the seller can claim.
     *
     * @param  array<string, mixed>  $predicate
     */
    public static function awaitsBuyer(array $predicate, DecisionFacts $facts): bool
    {
        return self::reach($predicate, $facts, true, 0) === self::BUYER_BOUND;
    }

    /**
     * @param  array<string, mixed>  $predicate
     */
    private static function reach(array $predicate, DecisionFacts $facts, bool $want, int $depth): int
    {
        if ($depth > 32 || array_key_exists('unsettled', $predicate)) {
            return self::UNREAD;
        }

        $value = self::test($predicate, $facts, $depth);

        if ($value !== null) {
            return $value === $want ? self::FREE : self::DEAD;
        }

        if (array_key_exists('not', $predicate)) {
            return is_array($predicate['not']) ? self::reach(Shape::map($predicate['not']), $facts, ! $want, $depth + 1) : self::UNREAD;
        }

        foreach (['all', 'any'] as $combinator) {
            $parts = $predicate[$combinator] ?? null;

            if (! is_array($parts)) {
                continue;
            }

            $reached = [];

            foreach ($parts as $part) {
                $reached[] = is_array($part) ? self::reach(Shape::map($part), $facts, $want, $depth + 1) : self::UNREAD;
            }

            // Every part must come out as wanted (`all` for true, `any` for false).
            if (($combinator === 'all') === $want) {
                return match (true) {
                    in_array(self::DEAD, $reached, true) => self::DEAD,
                    in_array(self::BUYER_BOUND, $reached, true) => self::BUYER_BOUND,
                    in_array(self::FREE, $reached, true) => self::FREE,
                    default => self::UNREAD,
                };
            }

            // One part will do.
            return match (true) {
                in_array(self::FREE, $reached, true) => self::FREE,
                in_array(self::BUYER_BOUND, $reached, true) => self::BUYER_BOUND,
                in_array(self::UNREAD, $reached, true) => self::UNREAD,
                default => self::DEAD,
            };
        }

        $fact = Shape::text($predicate['fact'] ?? null);

        return $fact !== null && in_array(strstr($fact, '.', true), self::BUYER, true) ? self::BUYER_BOUND : self::FREE;
    }

    /**
     * @param  array<string, mixed>  $predicate
     */
    private static function test(array $predicate, DecisionFacts $facts, int $depth): ?bool
    {
        if ($depth > 32) {
            return null;
        }

        if (array_key_exists('unsettled', $predicate)) {
            return null;
        }

        foreach (['all', 'any'] as $combinator) {
            if (array_key_exists($combinator, $predicate)) {
                $parts = $predicate[$combinator];

                if (! is_array($parts) || $parts === [] || ! array_is_list($parts)) {
                    return null;
                }

                return self::combine($combinator, $parts, $facts, $depth);
            }
        }

        if (array_key_exists('not', $predicate)) {
            $inner = is_array($predicate['not']) ? self::test(Shape::map($predicate['not']), $facts, $depth + 1) : null;

            return $inner === null ? null : ! $inner;
        }

        return self::leaf($predicate, $facts);
    }

    /**
     * @param  'all'|'any'  $combinator
     * @param  list<mixed>  $parts
     */
    private static function combine(string $combinator, array $parts, DecisionFacts $facts, int $depth): ?bool
    {
        $decisive = $combinator === 'any';
        $unknown = false;

        foreach ($parts as $part) {
            $value = is_array($part) ? self::test(Shape::map($part), $facts, $depth + 1) : null;

            if ($value === $decisive) {
                return $decisive;
            }

            if ($value === null) {
                $unknown = true;
            }
        }

        return $unknown ? null : ! $decisive;
    }

    /**
     * @param  array<string, mixed>  $predicate
     */
    private static function leaf(array $predicate, DecisionFacts $facts): ?bool
    {
        $fact = Shape::text($predicate['fact'] ?? null);
        $op = Shape::text($predicate['op'] ?? null);

        if ($fact === null || $op === null || ! $facts->has($fact)) {
            return null;
        }

        $actual = $facts->get($fact);
        $expected = $predicate['value'] ?? null;

        if ($op === 'prefix_in') {
            return self::prefixIn($actual, $expected);
        }

        if (in_array($op, self::NUMERIC_OPS, true)) {
            return self::compare($op, $actual, $expected);
        }

        return match ($op) {
            'eq' => is_string($expected) && self::numeric($actual) !== null && self::numeric($expected) !== null
                ? self::compare('eq', $actual, $expected)
                : $actual === $expected,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            default => null,
        };
    }

    /** A code carries its heading: `01022110` is under `0102` and under `01`. */
    private static function prefixIn(mixed $actual, mixed $prefixes): ?bool
    {
        if (! is_string($actual) || ! is_array($prefixes)) {
            return null;
        }

        $code = preg_replace('/[^0-9]/', '', $actual) ?? '';

        if ($code === '') {
            return null;
        }

        return array_any($prefixes, fn ($prefix): bool => is_string($prefix) && $prefix !== '' && str_starts_with($code, $prefix));
    }

    private static function compare(string $op, mixed $actual, mixed $expected): ?bool
    {
        $left = self::numeric($actual);
        $right = self::numeric($expected);

        if ($left === null || $right === null) {
            return null;
        }

        try {
            $sign = BigDecimal::of($left)->compareTo(BigDecimal::of($right));
        } catch (MathException) {
            return null;
        }

        return match ($op) {
            'eq' => $sign === 0,
            'exceeds' => $sign > 0,
            'at_least' => $sign >= 0,
            'below' => $sign < 0,
            'at_most' => $sign <= 0,
            default => null,
        };
    }

    /** A number as the decimal string it is, or null if it is not one. */
    private static function numeric(mixed $value): ?string
    {
        return match (true) {
            is_int($value), is_float($value) => (string) $value,
            is_string($value) && is_numeric($value) => $value,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $predicate
     * @param  list<string>  $names
     */
    private static function collect(array $predicate, array &$names, int $depth): void
    {
        if ($depth > 32) {
            return;
        }

        foreach (['all', 'any'] as $combinator) {
            if (is_array($predicate[$combinator] ?? null)) {
                foreach ($predicate[$combinator] as $part) {
                    if (is_array($part)) {
                        self::collect(Shape::map($part), $names, $depth + 1);
                    }
                }

                return;
            }
        }

        if (is_array($predicate['not'] ?? null)) {
            self::collect(Shape::map($predicate['not']), $names, $depth + 1);

            return;
        }

        $fact = Shape::text($predicate['fact'] ?? null);

        if ($fact !== null) {
            $names[] = $fact;
        }
    }
}
