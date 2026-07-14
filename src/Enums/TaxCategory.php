<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * The taxability class of what is being supplied. Rate sources key rates off this
 * plus the jurisdiction; the standard rate is the default, digital services are
 * called out because their place-of-supply rules differ.
 */
enum TaxCategory: string
{
    case Standard = 'standard';
    case DigitalService = 'digital_service';
}
