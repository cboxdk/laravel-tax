<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\ValueObjects\EuTerritory;
use DateTimeImmutable;

/**
 * Resolves the special VAT territory an address falls in, if any.
 *
 * Keyed on POSTAL CODE rather than subdivision, because subdivision is not
 * available for most of them. The addressing reference data carries no
 * subdivisions at all for Portugal, Finland, France or Greece, so the Azores,
 * Madeira, Åland and Corsica cannot be named that way — while every one of these
 * territories has its own postal range, and always has.
 *
 * A null return means the ordinary national rules apply, which is the answer for
 * the overwhelming majority of addresses. It is deliberately NOT an error: the
 * territories are the exception, and a seam that refused whenever it recognised
 * nothing would refuse almost every European sale.
 *
 * The corollary is that a MISSING postal code cannot be treated as mainland. An
 * address with no postcode is unresolvable rather than ordinary — see the shipped
 * implementation, which says so rather than assuming.
 */
interface EuTerritories
{
    /**
     * @param  DateTimeImmutable|null  $at  the supply date. Territory rates move —
     *                                      Madeira's reduced rate went from 5% to 4%
     *                                      on 2024-10-01 — so a back-dated supply
     *                                      must not be priced with today's.
     */
    public function for(CountryCode $country, ?string $postalCode, ?DateTimeImmutable $at = null): ?EuTerritory;
}
