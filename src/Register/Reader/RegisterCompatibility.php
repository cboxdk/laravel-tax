<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

use Cbox\Tax\Exceptions\DatasetUnreadable;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;

/**
 * Which register schemas and rule fields this reader has been reviewed against.
 *
 * FAIL CLOSED, in two places. A release on a schema nobody reviewed is not
 * compiled at all, and a rule carrying a field nobody reviewed stops the compile —
 * an unknown field may narrow when a rule applies, and applying the old scalar answer
 * without it is exactly the confident wrong number this package exists to avoid.
 *
 * Every field the schema publishes is therefore in one of two lists. INTERPRETED
 * fields are read and change the answer. UNINTERPRETED fields are ones the schema
 * defines and this reader has reviewed but does not act on: they do not stop a
 * compile — an Egyptian registration obligation should not block a Danish shop's rate
 * update — but a rule carrying one refuses when something actually reads it, because
 * reading it without them would be treating a qualified rule as unconditional.
 */
final class RegisterCompatibility
{
    /**
     * The newest minor reviewed, per major. Schema 2 is the consumer contract with
     * executable decisions; everything the reader relies on in 1.x is unchanged in it.
     *
     * @var array<int, int>
     */
    private const array LAST_REVIEWED_MINOR = [1 => 34, 2 => 5];

    /**
     * Payload fields this reader interprets, per rule kind it consumes.
     *
     * @var array<string, list<string>>
     */
    private const array PAYLOAD_FIELDS = [
        'threshold' => ['amount', 'currency', 'measuredOver', 'transactions', 'combinator', 'basis', 'counts', 'binds', 'appliesTo', 'consequence', 'graceDays', 'crossing', 'conditions', 'conditionsCombinator', 'none', 'amountOperator', 'transactionsOperator'],
        'sourcing' => ['basis', 'decision'],
        'holiday' => ['name', 'category', 'capAmount', 'capCurrency', 'capIsExclusive'],
        'marketplace_facilitator' => ['platformOwes', 'platformElects', 'deemedElection'],
        'price_exemption' => ['category', 'capAmount', 'capCurrency', 'capIsExclusive', 'above'],
        'remote_seller_election' => ['program', 'mechanic', 'ratePercent', 'statute'],
        'rounding' => ['method', 'places', 'appliesTo', 'aggregatesLocal'],
        'taxable_base' => ['component', 'included', 'category', 'proportion', 'decision'],
        'declined_rate' => ['reason', 'names', 'category', 'rate', 'says'],
    ];

    /**
     * Fields schema 2 defines that this reader does not act on. A rule carrying one
     * compiles, and refuses when read.
     *
     *  - `obligations` — what a registration threshold obliges (register within 30 days).
     *  - `measurementRules` — what counts toward the figure (exempt sales included).
     *  - `unresolvedQualifications` — statutory triggers the register has not modelled.
     *
     * All three sit on registration thresholds outside the United States today, which
     * no adapter here reads. On a US remote-seller threshold they would change what
     * the published figure means, so there they refuse rather than being dropped.
     *
     * @var array<string, list<string>>
     */
    private const array UNINTERPRETED_FIELDS = [
        'threshold' => ['obligations', 'measurementRules', 'unresolvedQualifications'],
    ];

    public static function schema(mixed $schema, string $where): void
    {
        $major = null;
        $minor = null;

        if (is_string($schema) && preg_match('/^([1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $schema, $parts) === 1) {
            $major = (int) $parts[1];
            $minor = (int) $parts[2];
        }

        if ($major === null || ! isset(self::LAST_REVIEWED_MINOR[$major]) || $minor > self::LAST_REVIEWED_MINOR[$major]) {
            throw DatasetUnreadable::unsupportedSchema($where, is_string($schema) ? $schema : null, self::supported());
        }
    }

    /**
     * A rule as it is COMPILED: unknown fields stop the compile, reviewed ones do not.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function rule(array $rule): void
    {
        self::check($rule, reading: false);
    }

    /**
     * A rule as it is READ to answer a question: anything not interpreted refuses.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function applicable(array $rule): void
    {
        self::check($rule, reading: true);
    }

    /** The reviewed range, for a message a person reads: "1.0–1.34, 2.0–2.4". */
    public static function supported(): string
    {
        $ranges = [];

        foreach (self::LAST_REVIEWED_MINOR as $major => $minor) {
            $ranges[] = sprintf('%d.0–%d.%d', $major, $major, $minor);
        }

        return implode(', ', $ranges);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private static function check(array $rule, bool $reading): void
    {
        $kind = Shape::text($rule['kind'] ?? null);

        if ($kind === null || ! isset(self::PAYLOAD_FIELDS[$kind])) {
            return;
        }

        $reviewed = [...self::PAYLOAD_FIELDS[$kind], ...self::UNINTERPRETED_FIELDS[$kind] ?? []];
        $unknown = array_diff(array_keys($rule), ['jurisdiction', 'kind', 'effective', 'payload', 'provenance']);
        $payload = array_keys(Shape::map($rule['payload'] ?? null));
        $unknownPayload = array_diff($payload, $reviewed);
        $uninterpreted = $reading ? array_intersect($payload, self::UNINTERPRETED_FIELDS[$kind] ?? []) : [];

        if ($unknown === [] && $unknownPayload === [] && $uninterpreted === []) {
            return;
        }

        $where = Shape::text($rule['jurisdiction'] ?? null) ?? '(unknown jurisdiction)';

        if ($unknown !== [] || $unknownPayload !== []) {
            throw new UnresolvedTaxRule(sprintf(
                'Unsupported fields on %s rule for %s: %s. Upgrade the reader; the rule cannot be treated as unconditional.',
                $kind,
                $where,
                implode(', ', [...$unknown, ...array_map(static fn (string $field): string => 'payload.'.$field, $unknownPayload)]),
            ));
        }

        throw new UnresolvedTaxRule(sprintf(
            'The %s rule for %s is qualified by %s, which this reader does not apply; it cannot be treated as unconditional.',
            $kind,
            $where,
            implode(', ', array_map(static fn (string $field): string => 'payload.'.$field, $uninterpreted)),
        ));
    }
}
