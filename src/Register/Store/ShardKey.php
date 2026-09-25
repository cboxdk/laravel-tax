<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Store;

/**
 * Which shard a jurisdiction code's records live in.
 *
 * One place, used by the compiler when writing and the reader when looking up,
 * because the two disagreeing is a lookup that finds nothing in a store that holds
 * it — and finding nothing is an answer here, not an error.
 *
 * Codes are regime-prefixed and may go deeper: `eu`, `eu:DK`, `us:WA:53033`. Only
 * the US is split further than its regime, because it is 38.3 MB of rates against
 * 4 MB for the other ten regimes put together.
 */
class ShardKey
{
    public static function of(string $jurisdiction): string
    {
        $parts = explode(':', $jurisdiction);
        $regime = $parts[0];

        if ($regime !== 'us') {
            return $regime;
        }

        // `us` with no segments is the regime itself, which carries facts of its own
        // — a federal rule hangs off it — so it gets a shard rather than being lost.
        return 'us/'.($parts[1] ?? '_US');
    }

    public static function regime(string $jurisdiction): string
    {
        return explode(':', $jurisdiction)[0];
    }
}
