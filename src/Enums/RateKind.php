<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * Which band of a jurisdiction's rate schedule a rate belongs to.
 */
enum RateKind: string
{
    case Standard = 'standard';
    case Reduced = 'reduced';
    case Zero = 'zero';
}
