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
         * `quarter_end_test_met` (Illinois and Vermont test at each quarter's end),
         * `registration_filed` (New York counts collection from the registration), or
         * `measurement_window_end` (schema 2.8: the last day of the threshold's own
         * fixed window in which the test is met — Connecticut's twelve months ended
         * 30 September, so a seller is in from the following 1 October).
         */
        public string $trigger,
        /**
         * How the date is counted from it: `at_trigger`, `calendar_days_after`,
         * `first_month_start_on_or_after_days`, `first_day_of_nth_following_month`,
         * `last_month_start_on_or_before_days` (Minnesota), `next_calendar_date` and
         * `date_in_year_after_trigger` (Pennsylvania's 1 April of the following year),
         * `return_due_after_following_filing_period` (schema 2.8, Hawaii: `days` after
         * the close of the seller's filing period FOLLOWING the one the trigger falls
         * in, its periods being the seller's own monthly, quarterly or semiannual
         * ones — the fact `seller.filingFrequency`; a trigger in the year's last
         * period is due on day `days` of the {@see $annualReturnMonth}-th month after
         * the year closes), and the rest of the closed list the register's schema
         * names; a kind outside it refuses when read. Computing it is the host's: only
         * it knows when its turnover crossed.
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
        /**
         * `return_due_after_following_filing_period` only: the month after the close
         * of the year in which the annual return is due (1–12). Hawaii: 4, so a
         * December crossing files by 20 April. Null for every other kind.
         */
        public ?int $annualReturnMonth = null,
    ) {}
}
