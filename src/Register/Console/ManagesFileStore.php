<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Console;

use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Illuminate\Console\Command;
use Throwable;

/**
 * For the `tax:data:*` commands: they manage the FILE STORE, and say so when the
 * engine is reading something else.
 *
 * A host may bind its own {@see RegisterData}. The store commands still work then —
 * the directory is still there, and a host moving from one to the other runs both
 * for a while — but what they install, verify or prune is not what prices a sale,
 * and a command that stayed silent about that would read as the opposite.
 *
 * @mixin Command
 */
trait ManagesFileStore
{
    /** One line, before anything else, when pricing does not read the file store. */
    protected function sayWhatPricingReads(): void
    {
        try {
            $bound = $this->laravel->make(RegisterData::class);
        } catch (Throwable $e) {
            $this->warn('The dataset the engine prices from could not be opened ('.$e->getMessage().'). This command manages the file store only.');

            return;
        }

        if ($bound instanceof RegisterDataset) {
            return;
        }

        $this->warn(sprintf(
            'The engine prices from %s (%s), not from the file store. This command manages the file store only; nothing it does changes what a calculation reads.',
            $bound::class,
            $bound->storeRoot(),
        ));
    }
}
