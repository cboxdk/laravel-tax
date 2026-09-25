<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\ValueObjects\DecisionFacts;

/**
 * Evaluates one of the register's published decision trees (schema 2) against the
 * facts of a transaction.
 *
 * The grammar is deliberately small, and this reads all of it and nothing more:
 *
 *  - a NODE carries a `condition`, `requiredFacts`, and three branches — `onTrue`,
 *    `onFalse` and `onUnknown` — each of which is either another node or an outcome;
 *  - a CONDITION is either a leaf `{fact, op, value}` with `op` of `eq` or `in`, or
 *    `{all: [conditions]}`;
 *  - an OUTCOME carries `status` — `resolved`, `unsupported` or `unknown` — and the
 *    fields that answer the rule's question.
 *
 * THREE-VALUED, ON PURPOSE. A missing fact does not count as false: it makes its
 * leaf unknown, `all` is unknown when nothing in it is false and something is
 * unknown, and the tree then follows `onUnknown`. Treating absent as false would
 * turn "the host did not say whether the freight was separately stated" into "it
 * was not", and take the branch that taxes it.
 *
 * Anything outside the grammar — another version, another operator, another
 * combinator — refuses. A reader that skipped a node it did not understand would
 * be answering a question the register did not ask.
 */
class Decision
{
    private const int VERSION = 1;

    /**
     * @param  array<string, mixed>  $node
     */
    public static function evaluate(array $node, DecisionFacts $facts, string $where): DecisionOutcome
    {
        // Depth is bounded by what the register can publish, not by us; a guard stops
        // a malformed document from recursing without end.
        for ($depth = 0; $depth < 32; $depth++) {
            if (! array_key_exists('condition', $node)) {
                return self::outcome($node, $where);
            }

            if (($node['version'] ?? null) !== self::VERSION) {
                throw new UnresolvedTaxRule(sprintf('Unsupported decision version on %s; this reader implements version %d.', $where, self::VERSION));
            }

            $branch = match (self::test(Shape::map($node['condition']), $facts, $where)) {
                true => 'onTrue',
                false => 'onFalse',
                null => 'onUnknown',
            };

            $next = $node[$branch] ?? null;

            if (! is_array($next)) {
                throw new UnresolvedTaxRule(sprintf('Decision on %s has no %s branch.', $where, $branch));
            }

            /** @var array<string, mixed> $next */
            $node = $next;
        }

        throw new UnresolvedTaxRule(sprintf('Decision on %s is nested too deeply to be a published rule.', $where));
    }

    /**
     * The facts a decision can ask for anywhere in its tree, so a refusal can name
     * all of them at once instead of one per attempt.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    public static function required(array $node): array
    {
        $listed = $node['requiredFacts'] ?? null;
        $names = is_array($listed) ? array_values(array_filter($listed, is_string(...))) : [];
        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private static function test(array $condition, DecisionFacts $facts, string $where): ?bool
    {
        if (array_key_exists('all', $condition)) {
            // STRICT, because a lenient reading fails open: an `all` that silently
            // dropped a part it could not read would be true on fewer conditions than
            // the register published.
            if (! is_array($condition['all']) || $condition['all'] === [] || ! array_is_list($condition['all'])) {
                throw new UnresolvedTaxRule(sprintf('Malformed "all" condition on %s.', $where));
            }

            $result = true;

            foreach ($condition['all'] as $part) {
                if (! is_array($part)) {
                    throw new UnresolvedTaxRule(sprintf('Malformed "all" condition on %s.', $where));
                }

                $value = self::test(Shape::map($part), $facts, $where);

                if ($value === false) {
                    return false;
                }

                if ($value === null) {
                    $result = null;
                }
            }

            return $result;
        }

        $fact = Shape::text($condition['fact'] ?? null);
        $op = Shape::text($condition['op'] ?? null);

        if ($fact === null || $op === null) {
            throw new UnresolvedTaxRule(sprintf('Unsupported decision condition on %s: %s.', $where, implode(', ', array_keys($condition))));
        }

        if (! $facts->has($fact)) {
            return null;
        }

        $actual = $facts->get($fact);
        $expected = $condition['value'] ?? null;

        return match ($op) {
            'eq' => self::same($actual, $expected),
            'in' => is_array($expected) && array_any($expected, static fn (mixed $one): bool => self::same($actual, $one)),
            default => throw new UnresolvedTaxRule(sprintf('Unsupported decision operator "%s" on %s.', $op, $where)),
        };
    }

    /**
     * Equality without PHP's loose coercion: `"0"` is not `false`, and `1` is not
     * `true`. Numbers compare by value so a count published as `100` matches `100.0`.
     */
    private static function same(mixed $actual, mixed $expected): bool
    {
        if ((is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected))) {
            return (float) $actual === (float) $expected;
        }

        return $actual === $expected;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function outcome(array $node, string $where): DecisionOutcome
    {
        $status = Shape::text($node['status'] ?? null);

        if (! in_array($status, ['resolved', 'unsupported', 'unknown'], true)) {
            throw new UnresolvedTaxRule(sprintf('Decision outcome on %s has status %s, which this reader does not implement.', $where, $status ?? '(none)'));
        }

        $fields = $node;
        unset($fields['status'], $fields['reason'], $fields['says']);

        return new DecisionOutcome($status, $fields, Shape::text($node['reason'] ?? null));
    }
}
