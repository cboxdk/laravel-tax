<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * What counts toward a remote-seller threshold, as the state states it.
 *
 * The figure alone does not settle whether a seller crossed it. Twelve states
 * publish a measurement rule beside the number — marketplace sales excluded from
 * the count, sales by affiliated persons aggregated into it, the unit being an
 * invoice rather than an order — and a seller measuring its own turnover against
 * the bare figure can be wrong in either direction by everything those rules move.
 *
 * Carried as the register's own strings rather than enums: the register names six
 * dimensions today and will name more, and a reader that refused an unknown one
 * would turn a state's extra precision into no answer at all. `says` is the state's
 * own sentence, which is what a person reviewing this actually reads.
 */
readonly class ThresholdMeasurement
{
    public function __construct(
        /** `marketplace_sales`, `affiliated_persons`, `exempt_sales`, `transaction_unit`… */
        public string $dimension,
        /** What happens to it: `included`, `excluded`, `aggregate`, `invoice`… */
        public string $treatment,
        public string $says,
    ) {}
}
