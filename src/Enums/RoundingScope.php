<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

enum RoundingScope: string
{
    case Line = 'line';
    case Invoice = 'invoice';
    case SellerElection = 'seller_election';
}
