<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime;

/**
 * A single-rate national VAT/GST regime — the UK, Switzerland, Norway, Australia,
 * New Zealand and Mexico all share this shape: destination tax at the national
 * rate, with a cross-border B2B reverse charge for tax-registered customers.
 * The national rate is sourced per jurisdiction via the rate source.
 */
class NationalTaxRegime extends DestinationTaxRegime
{
    protected function label(): string
    {
        return 'national VAT/GST';
    }
}
