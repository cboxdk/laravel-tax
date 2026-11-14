<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Contracts\UnstatedReadings;
use Cbox\Tax\Register\Reader\CategoryMap;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\UnstatedReading;
use DateTimeImmutable;

/**
 * The register's `read_unstated` rules, for a host to show.
 *
 * Read only here. No rate, taxability or rule the engine applies looks at them, so a
 * lookup in a category falls back exactly as it would without them. A record missing
 * a field the schema requires is left out rather than shown half-read.
 */
readonly class RegisterUnstatedReadings implements UnstatedReadings
{
    public function __construct(private RegisterData $dataset) {}

    public function for(Jurisdiction $place, string $categoryKey, ?DateTimeImmutable $at = null): array
    {
        $on = $at ?? new DateTimeImmutable('today');
        $code = $place->subdivision !== null && $place->country->value === 'US'
            ? UsCode::of($place->subdivision)
            : ($this->dataset->codesForCountry($place->country->value, $on)[0] ?? null);

        if ($code === null) {
            return [];
        }

        $rules = $this->dataset->rulesOn($code, 'read_unstated', $on);
        $readings = [];

        foreach (CategoryMap::ladder($categoryKey) as $rung) {
            foreach ($rules as $rule) {
                $payload = Shape::map($rule['payload'] ?? null);

                if (Shape::text($payload['category'] ?? null) !== $rung) {
                    continue;
                }

                $finding = Shape::text($payload['finding'] ?? null);
                $readOn = Shape::text($payload['readOn'] ?? null);
                $sections = array_values(array_filter(
                    array_map(Shape::text(...), is_array($payload['sections'] ?? null) ? $payload['sections'] : []),
                    is_string(...),
                ));

                if ($finding === null || $readOn === null || $sections === []) {
                    continue;
                }

                $readings[] = new UnstatedReading(
                    $code,
                    $rung,
                    $finding,
                    $sections,
                    $readOn,
                    ($payload['readOnIsCeiling'] ?? false) === true,
                    Shape::text(Shape::map($rule['provenance'] ?? null)['source'] ?? null),
                );
            }
        }

        return $readings;
    }
}
