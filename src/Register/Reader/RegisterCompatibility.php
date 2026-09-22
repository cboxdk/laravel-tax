<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

use Cbox\Tax\Exceptions\DatasetUnreadable;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;

/** Compatibility checks, not an interpreter for a future conditional-rule contract. */
final class RegisterCompatibility
{
    /** New minor schemas need a reader review before they can price a supply. */
    private const int LAST_REVIEWED_MINOR = 34;

    /**
     * Known payload fields of the rule kinds this package consumes, through 1.34.
     * Unknown fields may change applicability: retaining the old scalar answer is unsafe.
     * Existing threshold conditions describe compound thresholds, not delivery predicates.
     *
     * @var array<string, list<string>>
     */
    private const array PAYLOAD_FIELDS = [
        'threshold' => ['amount', 'currency', 'measuredOver', 'transactions', 'combinator', 'basis', 'counts', 'binds', 'appliesTo', 'consequence', 'graceDays', 'crossing', 'conditions', 'conditionsCombinator', 'none'],
        'sourcing' => ['basis'],
        'holiday' => ['name', 'category', 'capAmount', 'capCurrency', 'capIsExclusive'],
        'marketplace_facilitator' => ['platformOwes', 'platformElects', 'deemedElection'],
        'price_exemption' => ['category', 'capAmount', 'capCurrency', 'capIsExclusive', 'above'],
        'remote_seller_election' => ['program', 'mechanic', 'ratePercent', 'statute'],
        'rounding' => ['method', 'places', 'appliesTo', 'aggregatesLocal'],
        'taxable_base' => ['component', 'included', 'category', 'proportion'],
    ];

    public static function schema(mixed $schema, string $where): void
    {
        if (! is_string($schema)
            || preg_match('/^1\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $schema, $parts) !== 1
            || (int) $parts[1] > self::LAST_REVIEWED_MINOR) {
            throw DatasetUnreadable::corruptShard(
                $where,
                sprintf('unsupported schemaVersion %s; this reader supports 1.0.x through 1.%d.x. Upgrade the reader before using this release', is_string($schema) ? $schema : '(missing)', self::LAST_REVIEWED_MINOR),
            );
        }
    }

    /** @param array<string, mixed> $rule */
    public static function rule(array $rule): void
    {
        $kind = Shape::text($rule['kind'] ?? null);

        if ($kind === null || ! isset(self::PAYLOAD_FIELDS[$kind])) {
            return;
        }

        $unknown = array_diff(array_keys($rule), ['jurisdiction', 'kind', 'effective', 'payload', 'provenance']);
        $unknownPayload = array_diff(array_keys(Shape::map($rule['payload'] ?? null)), self::PAYLOAD_FIELDS[$kind]);

        if ($unknown !== [] || $unknownPayload !== []) {
            throw new UnresolvedTaxRule(sprintf(
                'Unsupported fields on %s rule for %s: %s. Upgrade the reader; the rule cannot be treated as unconditional.',
                $kind,
                Shape::text($rule['jurisdiction'] ?? null) ?? '(unknown jurisdiction)',
                implode(', ', [...$unknown, ...array_map(static fn (string $field): string => 'payload.'.$field, $unknownPayload)]),
            ));
        }
    }
}
