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
readonly class ThresholdObligation
{
    public function __construct(
        /** `register`, `collect`, `remit`, `registration_effective`. */
        public string $action,
        /**
         * What starts the clock: `threshold_met`, `first_crossing_in_current_year`,
         * `quarter_end_test_met` (Illinois and Vermont test at each quarter's end), or
         * `registration_filed` (New York counts collection from the registration).
         */
        public string $trigger,
        /**
         * How the date is counted from it: `at_trigger`, `calendar_days_after`,
         * `first_month_start_on_or_after_days`, `first_day_of_nth_following_month`,
         * `last_month_start_on_or_before_days` (Minnesota), `next_calendar_date` and
         * `date_in_year_after_trigger` (Pennsylvania's 1 April of the following year),
         * and others the register names. Computing it is the host's: only it knows
         * when its turnover crossed.
         */
        public string $dateKind,
        /** The figure `dateKind` counts, in days or months; null where it counts none. */
        public ?int $dateFigure,
        public string $says,
        /**
         * The calendar month a fixed date names (1–12) — `next_calendar_date`: North
         * Macedonia's "by 15 January" is month 1, day 15. Null where the kind counts
         * from the trigger instead.
         */
        public ?int $month = null,
        /** The day of that month; null with `month`. */
        public ?int $day = null,
    ) {}
}
