<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * How a US state sources an INTRASTATE sale — mirrors the `sourcing.mode` field of
 * the us-tax-data dataset. `Origin` follows the seller's location, `Destination`
 * the buyer's, `Mixed` splits by jurisdiction layer or seller type (the dataset
 * note spells out the split). Interstate/remote sales are destination-sourced
 * everywhere regardless, so this only refines the local rate for in-state supplies.
 */
enum SourcingMode: string
{
    case Origin = 'origin';
    case Destination = 'destination';
    case Mixed = 'mixed';
}
