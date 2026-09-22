<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Cbox\Tax\Enums\RefusalReason;
use RuntimeException;

/**
 * No compiled register is on disk, so there is nothing to price with.
 *
 * THE FIRST-RUN FAILURE, AND IT IS DELIBERATELY LOUD. No rate data ships inside this
 * package: it is MIT and the register is PolyForm Internal Use, and bundling the one
 * inside the other would mislabel it. So a fresh install has an empty store until
 * `tax:data:sync` has run.
 *
 * The only unacceptable behaviour here would be a plausible number. A missing store
 * must never degrade to a stale snapshot or a guessed standard rate, because an
 * invoice priced from either is wrong in a way nobody notices until a return is
 * filed. It refuses, and names the command that fixes it.
 */
class DatasetNotInstalled extends RuntimeException implements Refusal
{
    public function __construct(private readonly string $root, ?string $version = null)
    {
        parent::__construct($version === null ? sprintf(
            'No tax register is installed at %s. Run `php artisan tax:data:sync` to compile one (about 6.5 MB over the wire), or point tax.register.store at an existing store.',
            $root,
        ) : sprintf(
            'Pinned tax register %s is not installed at %s. Run `php artisan tax:data:sync` to install the configured release, or change tax.register.version.',
            $version,
            $root,
        ));
    }

    /**
     * `RateUnavailable`, not a new case: from the caller's side this is exactly the
     * situation that reason describes — the engine cannot price this supply and a
     * retry will not change that. What differs is the remedy, and the remedy is in
     * the message.
     */
    public function reason(): RefusalReason
    {
        return RefusalReason::RateUnavailable;
    }

    public function root(): string
    {
        return $this->root;
    }
}
