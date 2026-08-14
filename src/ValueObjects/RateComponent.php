<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Math\BigDecimal;
use Cbox\Tax\Enums\JurisdictionLevel;

/**
 * One taxing authority's share of a stacked rate, as a percentage (1.625 = 1.625%).
 *
 * A source that stacks several authorities into a single all-in figure — the US
 * state share plus each county/city/special-district record — knows the split
 * while it sums, and this is where that knowledge is KEPT rather than discarded.
 * It is what lets an assessment say *who* gets which part of the tax, which a
 * per-jurisdiction remittance needs and one combined percentage cannot give.
 *
 * Provenance, never invention: `code` is the authority's identifier in the source
 * that supplied it (a dataset jurisdiction code) and `name` its published name.
 * A source carrying neither leaves both null instead of deriving a plausible
 * label — an unnamed share is honest, a guessed one is not.
 */
readonly class RateComponent
{
    public BigDecimal $percentage;

    public function __construct(
        public JurisdictionLevel $level,
        BigDecimal|string|int $percentage,
        public ?string $code = null,
        public ?string $name = null,
    ) {
        $this->percentage = BigDecimal::of($percentage);
    }

    /** A short label for audit trails: the authority's name, else its code, else its level. */
    public function label(): string
    {
        return $this->name ?? $this->code ?? $this->level->value;
    }
}
