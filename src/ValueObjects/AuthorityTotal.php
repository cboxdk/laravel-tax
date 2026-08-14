<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Tax\Enums\JurisdictionLevel;

/**
 * What one taxing authority is owed across a whole document.
 *
 * Deliberately NOT a {@see BreakdownLine}. A per-supply breakdown line carries the
 * percentage that produced it; a document-level total cannot, because two lines
 * can hit the same authority at different rates — a standard-rated line and a
 * reduced-rated one both pay the county, at different percentages, and no single
 * number describes the pair. Money per authority is what a remittance needs, and
 * it is what this states. Claiming a rate as well would mean inventing one.
 */
readonly class AuthorityTotal
{
    public function __construct(
        public JurisdictionLevel $level,
        public Money $tax,
        public ?string $code = null,
        public ?string $name = null,
    ) {}

    /** A short label for audit trails: the authority's name, else its code, else its level. */
    public function label(): string
    {
        return $this->name ?? $this->code ?? $this->level->value;
    }
}
