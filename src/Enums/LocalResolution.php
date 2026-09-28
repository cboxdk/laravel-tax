<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * What a local answer in a US state needs, as the register states it.
 *
 * A state with no local tax needs nothing below the state; a state whose only local
 * authority is the county needs the county's name; everywhere else a local rate
 * depends on the address itself — a ZIP+4, a street or a point.
 */
enum LocalResolution: string
{
    case State = 'state';
    case County = 'county';
    case Address = 'address';

    /** Whether an address has to be geocoded below the state line to price it. */
    public function needsGeocoding(): bool
    {
        return $this !== self::State;
    }
}
