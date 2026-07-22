<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * How a jurisdiction's rate records combine — mirrors the `rateBasis` field of the
 * us-tax-data dataset. It decides the arithmetic and must not be guessed:
 *
 *  - `Component`: the state rate and each applicable local record are separate
 *    addends; the all-in rate is their sum.
 *  - `Combined`: one record already IS the all-in total (the state share is inside
 *    it); summing it with the state rate would double-count.
 */
enum RateBasis: string
{
    case Component = 'component';
    case Combined = 'combined';
}
