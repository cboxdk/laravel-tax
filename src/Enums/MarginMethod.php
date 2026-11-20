<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * How a dealer works out the margin the tax is charged on.
 *
 * `Individual` is the scheme itself: one sale, one margin (Art. 315). `Global` is the
 * simplification some states allow under Art. 318 — the Dutch globalisatieregeling,
 * the German Gesamtdifferenz, the UK's global accounting — where the margin is the
 * period's sales less the period's purchases. Under it ONE SALE HAS NO TAX OF ITS OWN:
 * the figure exists only on the return, and an engine that printed one per line would
 * be inventing it.
 */
enum MarginMethod: string
{
    case Individual = 'individual';
    case Global = 'global';

    /** The register's name for the basis: `margin` per item, `global_accounting` per period. */
    public function basis(): string
    {
        return $this === self::Individual ? 'margin' : 'global_accounting';
    }
}
