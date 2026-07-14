<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * Whether the customer is a business or a private consumer. This is the B2B/B2C
 * test that gates reverse-charge and place-of-supply rules.
 */
enum CustomerType: string
{
    case Business = 'business';
    case Consumer = 'consumer';
}
