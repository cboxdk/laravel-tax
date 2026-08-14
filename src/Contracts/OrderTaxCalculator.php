<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Tax\ValueObjects\OrderAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;

/**
 * A {@see TaxCalculator} that can also assess a whole document.
 *
 * A separate contract rather than a second method on {@see TaxCalculator}, for the
 * same reason {@see CommodityRateSource} is separate from {@see TaxRateSource}: a
 * host that has bound its own single-supply calculator should not be broken by a
 * capability it never asked for, and a caller can test for the capability rather
 * than assume it.
 */
interface OrderTaxCalculator extends TaxCalculator
{
    public function assessOrder(TaxOrder $order): OrderAssessment;
}
