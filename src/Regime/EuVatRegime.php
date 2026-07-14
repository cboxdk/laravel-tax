<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime;

/**
 * EU VAT. Digital/B2C supplies are taxed at the customer's Member State rate
 * (destination); intra-EU B2B supplies to a VIES-validated customer reverse-charge.
 * Rates are sourced (e.g. from the EU Commission's TEDB feed) via the rate source.
 */
class EuVatRegime extends DestinationTaxRegime
{
    protected function label(): string
    {
        return 'EU VAT';
    }
}
