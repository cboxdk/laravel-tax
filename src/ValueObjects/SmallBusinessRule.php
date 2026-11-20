<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * One member state's small-business exemption, as the register publishes it: the
 * annual turnover it is open under, the Union turnover a seller from another member
 * state must also stay within, and what the invoice says.
 */
readonly class SmallBusinessRule
{
    public function __construct(
        public string $jurisdiction,
        /** The state's annual turnover threshold and its currency. */
        public string $threshold,
        public string $currency,
        /** The Union annual turnover cap for a seller established in another member state; null where the state has not opened the scheme to them. */
        public ?string $unionThreshold = null,
        public ?string $unionCurrency = null,
        public ?InvoiceMention $mention = null,
        public ?string $citation = null,
        public ?string $source = null,
        /** Whether passing the threshold also shuts the seller out for the following calendar year. */
        public bool $excludesFollowingYear = false,
    ) {}
}
