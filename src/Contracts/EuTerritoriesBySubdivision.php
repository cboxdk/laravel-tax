<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\ValueObjects\EuTerritory;
use DateTimeImmutable;

/**
 * Optional companion to {@see EuTerritories}: a territory named by its ISO 3166-2
 * subdivision rather than a postcode, and which countries cannot be read as
 * "mainland" without one of the two.
 *
 * An address geocoded to `ES-TF` (Santa Cruz de Tenerife) with no postcode was
 * priced at mainland Spanish VAT and marked reliable — for a delivery to the Canary
 * Islands, which is an export.
 */
interface EuTerritoriesBySubdivision extends EuTerritories
{
    public function forSubdivision(SubdivisionCode $subdivision, ?DateTimeImmutable $at = null): ?EuTerritory;

    /** Whether a supply into this country needs a postcode or subdivision to be placed. */
    public function needsPlacement(CountryCode $country): bool;
}
