<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

use Cbox\Tax\Contracts\ProductTaxability;

/**
 * The taxability treatment a jurisdiction applies to a product category — mirrors
 * the `treatment` field of the us-tax-data dataset. `Taxable`/`Exempt` are at the
 * general rate; `ReducedRate` and `Conditional` carry structured `conditions` (a
 * reduced percentage, or a threshold such as an exempt-below-price) that a coarse
 * taxable boolean cannot represent.
 */
enum TaxabilityTreatment: string
{
    case Taxable = 'taxable';
    case Exempt = 'exempt';
    case ReducedRate = 'reduced_rate';
    case Conditional = 'conditional';

    /**
     * The coarse taxable signal the {@see ProductTaxability}
     * boolean seam needs: false only for a clean exemption, true otherwise. Use the
     * treatment plus its conditions for the exact rule.
     */
    public function isTaxable(): bool
    {
        return $this !== self::Exempt;
    }
}
