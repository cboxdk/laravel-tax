<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * What crossing a remote-seller threshold obliges, and from when.
 *
 * Crossing is not the same day as collecting. Arizona has a seller begin remitting
 * "on the first day of the month that starts at least thirty days after the
 * threshold is met", and a system that starts charging at the crossing bills tax
 * for up to two months where the state asked for none — with the customer's money.
 *
 * The date is carried as the register's own shape, `kind` plus a figure, because
 * computing it needs the crossing date, which this package is never told: the host
 * knows when its own turnover crossed.
 */
final readonly class ThresholdObligation
{
    public function __construct(
        /** `register`, `collect`, `remit`, `registration_effective`. */
        public string $action,
        /** `threshold_met` or `first_crossing_in_current_year`. */
        public string $trigger,
        /** `at_trigger`, `calendar_days_after`, `first_month_start_on_or_after_days`… */
        public string $dateKind,
        /** The figure `dateKind` counts, in days or months; null where it counts none. */
        public ?int $dateFigure,
        public string $says,
    ) {}
}
