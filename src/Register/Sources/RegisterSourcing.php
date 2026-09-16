<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Enums\SourcingMode;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\IntrastateSourcing;

/**
 * Whether a state taxes at the origin of a sale or its destination, from the
 * register's `sourcing` rules.
 *
 * The register's own vocabulary is the engine's: origin, destination, mixed — 225
 * destination, 7 origin and 8 mixed across the whole register. `mixed` is not a
 * shrug; it is states like Alabama whose rule turns on where title passes, which is
 * neither of the other two and must not be flattened into one of them.
 *
 * Null where nothing is published. The regime reads that as destination, which is
 * what it did before intrastate sourcing was modelled at all.
 */
final readonly class RegisterSourcing implements SourcingRules
{
    public function __construct(private RegisterDataset $dataset) {}

    public function for(SubdivisionCode $state): ?IntrastateSourcing
    {
        $rules = $this->dataset->rulesFor(UsCode::of($state), 'sourcing');
        $rule = $rules[0] ?? null;

        if ($rule === null) {
            return null;
        }

        $basis = Shape::text(Shape::map($rule['payload'] ?? null)['basis'] ?? null);
        $mode = $basis === null ? null : SourcingMode::tryFrom($basis);

        if ($mode === null) {
            return null;
        }

        return new IntrastateSourcing($mode, Shape::text(Shape::map($rule['provenance'] ?? null)['note'] ?? null));
    }
}
