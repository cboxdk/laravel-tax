<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use InvalidArgumentException;

/**
 * Transaction facts a published decision can test, keyed by the register's own fact
 * names: `delivery.separatelyStated`, `route.intrastate`, `seller.singlePlaceOfBusiness`.
 *
 * The register states a conditional rule as a decision tree over named facts, and
 * the host is the only party that knows most of them — whether freight was stated
 * separately on the invoice, what it was labelled, whether the charge is the true
 * cost. So they arrive here as plain values under the register's names rather than
 * as fields on a class this package would have to grow every time the register
 * names a new one.
 *
 * ABSENT IS NOT FALSE. A fact nobody supplied makes the condition that tests it
 * unknown, and a decision that reaches an unknown refuses instead of taking either
 * branch. That is the whole reason the map is sparse.
 */
final readonly class DecisionFacts
{
    /** @var array<string, string|int|float|bool> */
    public array $values;

    /**
     * @param  array<string, string|int|float|bool>  $values
     */
    public function __construct(array $values = [])
    {
        foreach ($values as $name => $value) {
            self::assertName($name);
        }

        $this->values = $values;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name): string|int|float|bool|null
    {
        return $this->values[$name] ?? null;
    }

    public function with(string $name, string|int|float|bool $value): self
    {
        self::assertName($name);

        return new self([...$this->values, $name => $value]);
    }

    /**
     * Fill a fact only where the host has not stated it. The engine can often infer a
     * fact from the query — whether delivered goods were taxable, say — but the host
     * may know the whole shipment where the engine sees one line, so a stated fact
     * wins.
     */
    public function withDefault(string $name, string|int|float|bool $value): self
    {
        return $this->has($name) ? $this : $this->with($name, $value);
    }

    private static function assertName(mixed $name): void
    {
        if (! is_string($name) || preg_match('/^[a-z][A-Za-z]*(\.[a-z][A-Za-z]*)+$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Decision fact names are the register\'s dotted names, such as "delivery.separatelyStated"; got %s.',
                is_string($name) ? '"'.$name.'"' : get_debug_type($name),
            ));
        }
    }
}
