<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\ValueObjects\Attribution;
use Cbox\Tax\ValueObjects\DecisionFacts;
use DateTimeImmutable;

/**
 * Who accounts for the tax on a supply in a member state by a supplier not established
 * there (Art. 194 of the VAT Directive).
 *
 * Every member state has such a rule and they differ in what they cover: Germany
 * reverses work deliveries and services by foreign businesses but not plain deliveries
 * of goods; France and the Netherlands reverse goods too. The rule's conditions decide,
 * read against the supply's facts.
 */
interface AttributionRules
{
    public function verdict(CountryCode $state, DecisionFacts $facts, DateTimeImmutable $on): Attribution;
}
