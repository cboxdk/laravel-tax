<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Tax\Enums\JurisdictionLevel;

/**
 * How an assessment's tax splits across the authorities that levy it — the state
 * share, each county/city/special-district share — with the parts summing EXACTLY
 * to the assessment's tax.
 *
 * That exactness is the whole point, and it is why the split is allocated from the
 * total rather than recomputed per authority: a remittance filed per jurisdiction
 * must add back up to what was charged on the invoice, and applying each rate to
 * the net independently leaves a rounding remainder that does not.
 *
 * Present only when the rate source decomposed the rate ({@see TaxRate::$components}).
 * A null breakdown on an assessment means "not decomposable", never "all of it goes
 * to one authority".
 */
readonly class TaxBreakdown
{
    /**
     * @param  list<BreakdownLine>  $lines
     */
    public function __construct(public array $lines = []) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * The lines levied at one layer of government. Several may share a level — a
     * rooftop address can sit inside more than one special district.
     *
     * @return list<BreakdownLine>
     */
    public function atLevel(JurisdictionLevel $level): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (BreakdownLine $line): bool => $line->level === $level,
        ));
    }

    /**
     * The tax across all lines — equal to the assessment's tax by construction.
     * Null only for an empty breakdown, which carries no currency to total in.
     */
    public function total(): ?Money
    {
        $total = null;

        foreach ($this->lines as $line) {
            $total = $total === null ? $line->tax : $total->plus($line->tax);
        }

        return $total;
    }
}
