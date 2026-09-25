<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\SubdivisionCode;

/**
 * An ISO subdivision code as the register addresses it: `US-KS` becomes `us:KS`.
 *
 * One function, in one place, because the two vocabularies are one substitution
 * apart and writing that substitution at each call site is how half of them end up
 * uppercase.
 */
class UsCode
{
    public static function of(SubdivisionCode $state): string
    {
        return 'us:'.substr($state->value, 3);
    }
}
