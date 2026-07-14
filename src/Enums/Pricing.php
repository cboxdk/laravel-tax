<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * Whether a supplied amount is tax-exclusive (net, tax added on top) or
 * tax-inclusive (gross, tax extracted from within).
 */
enum Pricing: string
{
    case Exclusive = 'exclusive';
    case Inclusive = 'inclusive';
}
