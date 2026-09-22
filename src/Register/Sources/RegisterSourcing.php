<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Enums\SourcingMode;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\IntrastateSourcing;
use DateTimeImmutable;

/**
 * Whether a state taxes at the origin of a sale or its destination, from the
 * register's `sourcing` rules.
 *
 * Reads origin, destination or mixed from the window covering the requested date.
 * Mixed rules need a decision per authority layer and must not be flattened into
 * one place by the regime.
 *
 * Null where nothing applies. The regime refuses an intrastate route when a bound
 * source cannot supply the rule needed to choose between its two places.
 */
final readonly class RegisterSourcing implements SourcingRules
{
    public function __construct(private RegisterDataset $dataset) {}

    public function for(SubdivisionCode $state, ?DateTimeImmutable $at = null): ?IntrastateSourcing
    {
        $rules = $this->dataset->rulesOn(UsCode::of($state), 'sourcing', $at ?? new DateTimeImmutable);

        if (count($rules) > 1) {
            throw new UnresolvedTaxRule('Overlapping sourcing rules for '.$state->value.'.');
        }
        $rule = $rules[0] ?? null;

        if ($rule === null) {
            return null;
        }

        $basis = Shape::text(Shape::map($rule['payload'] ?? null)['basis'] ?? null);
        $mode = $basis === null ? null : SourcingMode::tryFrom($basis);

        if ($mode === null) {
            throw new UnresolvedTaxRule('Unknown sourcing basis for '.$state->value.'.');
        }

        return new IntrastateSourcing($mode, Shape::text(Shape::map($rule['provenance'] ?? null)['note'] ?? null));
    }
}
