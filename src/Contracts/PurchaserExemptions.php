<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\PurchaserType;
use Cbox\Tax\ValueObjects\DecisionFacts;
use Cbox\Tax\ValueObjects\PurchaserExemption;
use DateTimeImmutable;

/**
 * What a place's own rule does for a purchaser — the question a stated purchaser asks.
 *
 * Each place decides for itself: a charitable organisation is exempt in Texas and taxable
 * in Alabama; a diplomat's purchases in a member state follow its own limits under
 * Art. 151. Never an assumed exemption: a place that states nothing answers so.
 */
interface PurchaserExemptions
{
    public function for(Jurisdiction $place, PurchaserType $purchaser, DecisionFacts $facts, DateTimeImmutable $on): PurchaserExemption;
}
