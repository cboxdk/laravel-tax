<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Tax\ValueObjects\FlatCharge;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * Supplies the fixed-amount charges a supply attracts — Colorado's Retail Delivery
 * Fee, Minnesota's, a bag or e-waste levy.
 *
 * A DATA seam like {@see TaxRateSource}, and the package **ships none**, for the
 * same reason it ships no reduced-rate bands: there is no authoritative compilation
 * of these behind the us-tax-data dataset, and inventing one would be worse than
 * the gap. What was missing was not the data but the ability to express it — a host
 * that knows its own obligations had nowhere to put them.
 *
 * The ASSESSMENT is passed as well as the query because applicability usually turns
 * on the outcome: Colorado's fee is due on a delivery that contains taxable
 * tangible goods, so a source has to see whether tax was charged at all.
 */
interface FlatChargeSource
{
    /**
     * @return list<FlatCharge>
     */
    public function chargesFor(TaxQuery $query, TaxAssessment $assessment): array;
}
