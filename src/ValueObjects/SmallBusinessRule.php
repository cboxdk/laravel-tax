<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * One member state's small-business exemption, as the register publishes it: every
 * turnover limit that must hold, what the supply that passes them bears, the Union
 * turnover a seller from another member state must also stay within, and what the
 * invoice says. `available: false` is a state that has none (Spain).
 */
readonly class SmallBusinessRule
{
    /** The supply that passes the ceiling is taxed in full, and every supply after it. */
    public const string CROSSING_SUPPLY = 'crossing_supply';

    /** The supply that passes the ceiling is still exempt; tax starts with the next (Luxembourg, Czechia). */
    public const string AFTER_CROSSING_SUPPLY = 'after_crossing_supply';

    /** Only the part of the supply above the ceiling is taxed (Latvia). */
    public const string EXCESS_ONLY = 'excess_only';

    /**
     * @param  list<SmallBusinessThreshold>  $thresholds
     * @param  list<string>  $exclusions  what the law takes out of the exemption, in words
     */
    public function __construct(
        public string $jurisdiction,
        /** The currency every threshold is stated in. */
        public string $currency,
        public array $thresholds = [],
        /** The Union annual turnover cap for a seller established in another member state; null where the state has not opened the scheme to them. */
        public ?string $unionThreshold = null,
        public ?string $unionCurrency = null,
        public ?InvoiceMention $mention = null,
        public ?string $citation = null,
        public ?string $source = null,
        public string $crossing = self::CROSSING_SUPPLY,
        /** Calendar years the seller is shut out after passing a limit: 0, 1 or 2. */
        public int $excludesFollowingYears = 0,
        public bool $available = true,
        /** Where the state has none, the publisher's sentence that shows it. */
        public ?string $unavailableBecause = null,
        public array $exclusions = [],
    ) {}

    /** The headline figure: the first limit on this year's turnover, else the first limit. */
    public function threshold(): ?string
    {
        foreach ($this->thresholds as $threshold) {
            if ($threshold->measuredOver === SmallBusinessThreshold::CALENDAR_YEAR && $threshold->appliesTo === 'all' && ! $threshold->firstYearOfActivity) {
                return $threshold->amount;
            }
        }

        return $this->thresholds[0]->amount ?? null;
    }
}
