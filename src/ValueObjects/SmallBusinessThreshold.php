<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * One turnover limit of a member state's small-business exemption. A state can set
 * several that must all hold: a figure for the previous year and one for this year
 * (Germany, Austria), goods and services apart (France, Ireland), a figure only for
 * the first year of activity (Germany, Belgium), one for a named sector (France's
 * professions, Denmark's first sale of an artist's own work).
 */
readonly class SmallBusinessThreshold
{
    public const string CALENDAR_YEAR = 'calendar_year';

    public const string PREVIOUS_CALENDAR_YEAR = 'previous_calendar_year';

    public const string ROLLING_12_MONTHS = 'rolling_12_months';

    public const string SERVICES = 'services';

    /** France's services column: services other than food served on the premises and lodging (CGI 293 B I). */
    public const string SERVICES_OTHER_THAN_FOOD_AND_LODGING = 'services_other_than_food_and_lodging';

    public function __construct(
        public string $amount,
        /** `calendar_year`, `previous_calendar_year` or `rolling_12_months`. */
        public string $measuredOver,
        /** `all`, `services`, `services_other_than_food_and_lodging`, or a sector key the law names. */
        public string $appliesTo = 'all',
        public bool $firstYearOfActivity = false,
        /** Art. 288a(1): within this ceiling the current year stays exempt. Null where the state did not take it up. */
        public ?string $tolerance = null,
        /** Applies only when goods are at least this percentage of the turnover (Ireland's 90). */
        public ?string $goodsShareAtLeast = null,
        /** Applies only when goods are below this percentage of the turnover. */
        public ?string $goodsShareBelow = null,
    ) {}

    /** The figure a supply must not take the turnover past: the tolerance where there is one. */
    public function ceiling(): string
    {
        return $this->tolerance ?? $this->amount;
    }
}
