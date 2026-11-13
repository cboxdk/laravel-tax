<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Cbox\Tax\Enums\JurisdictionLevel;

/**
 * One taxing authority's share of an assessed supply — a {@see RateComponent}
 * with the money attached: the base it was applied to and the tax that accrued
 * to that authority.
 *
 * `taxableAmount` is the whole net of the supply on every line, because the
 * engine applies one taxable base across the stack. That is correct for the
 * states modelled today; it would NOT be for a state that exempts a category at
 * the state level while its localities still tax it (Illinois and Missouri do
 * this for groceries). Modelling that needs a per-level taxability seam, and
 * until it exists a per-level base is deliberately not claimed here.
 *
 * Where the rate is computed on a share of the price ({@see TaxRate::$taxableShare}),
 * it is that share of the net on every line: Texas taxes data processing on 80% of
 * the price, and its cities, counties and transit authorities on the same 80%.
 */
readonly class BreakdownLine
{
    public function __construct(
        public JurisdictionLevel $level,
        public BigDecimal $percentage,
        public Money $taxableAmount,
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
