<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use InvalidArgumentException;

/**
 * A category key the installed register release does not publish.
 *
 * A caller error, and one the enum could never catch: `services.educaton` is a typo,
 * and `goods.art.philatelic` is a real key that a store pinned to an older release
 * may not yet carry. Both are refused before a rate is looked up, because the
 * alternative is climbing from a key that is not there to whatever its prefix
 * happens to match.
 */
class UnknownCategory extends InvalidArgumentException implements Malformed
{
    /**
     * @param  list<string>  $nearby
     */
    public static function notPublished(string $key, string $version, array $nearby): self
    {
        return new self(sprintf(
            'Category "%s" is not published by register release %s.%s',
            $key,
            $version,
            $nearby === [] ? '' : ' Published nearby: '.implode(', ', $nearby).'.',
        ));
    }
}
