<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

/**
 * Narrow `mixed` from a decoded document into the shapes this package reads.
 *
 * Everything out of `json_decode` is `mixed`, and the choice at each of those
 * boundaries is between asserting a shape and checking one. Asserting it is how a
 * malformed store becomes a TypeError in the middle of pricing an order; checking it
 * here means a field that is not what it should be reads as absent, which every
 * caller already handles — they are all written around a publisher that may not
 * carry a field yet.
 *
 * Absent and malformed are deliberately the same answer. The difference matters to
 * whoever is fixing the register and not at all to an invoice, and a reader that
 * distinguished them would have to decide which one to bill on.
 */
class Shape
{
    /**
     * The rows of an array of objects, skipping anything that is not one.
     *
     * @return list<array<string, mixed>>
     */
    public static function records(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    public static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A scalar rendered as a string, for building a comparison key. */
    public static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
