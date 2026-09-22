<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

/**
 * Where a published decision tree ended up for one transaction.
 *
 * `resolved` carries the answer in `fields` — `baseTreatment` for a taxable-base
 * rule, `layers` for a sourcing rule. `unsupported` means the register knows the
 * case exists and has not modelled it; `unknown` means a fact it needs was not
 * supplied. Neither is an answer, and neither may be treated as one.
 */
final readonly class DecisionOutcome
{
    /**
     * @param  'resolved'|'unsupported'|'unknown'  $status
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public string $status,
        public array $fields,
        public ?string $reason,
    ) {}

    public function resolved(): bool
    {
        return $this->status === 'resolved';
    }
}
