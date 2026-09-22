<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * Whether a threshold is crossed on reaching the figure or only on passing it.
 *
 * New York's remote-seller test is sales that EXCEED $500,000 and more than 100
 * transactions; others are "$100,000 or more". The register states which, per limb.
 * The difference is one dollar and one sale, and a hint that names the wrong one
 * tells a seller they are obliged a transaction early — or not yet, a transaction late.
 */
enum ThresholdOperator: string
{
    case Exceeds = 'exceeds';
    case AtLeast = 'at_least';
}
