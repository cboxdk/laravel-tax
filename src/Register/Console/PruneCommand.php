<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Console;

use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Remove old installed versions, keeping the newest few.
 *
 * THE LIVE VERSION IS NEVER REMOVED, whatever the arithmetic says. Deleting what the
 * pointer names would take the engine down to buy back 63 MB, which is not a trade
 * anybody would make deliberately.
 */
final class PruneCommand extends Command
{
    protected $signature = 'tax:data:prune {--keep= : How many versions to retain, newest first; defaults to tax.register.keep}';

    protected $description = 'Delete old installed register versions';

    public function handle(StoreLayout $layout, StorePointer $pointer, Config $config): int
    {
        $keep = max(1, (int) Shape::scalar($this->option('keep') ?? $config->get('tax.register.keep', 2)));
        $installed = $layout->installed();
        $live = $pointer->current();
        $pin = Shape::text($config->get('tax.register.version'));

        $doomed = array_slice($installed, 0, max(0, count($installed) - $keep));
        $removed = 0;

        foreach ($doomed as $version) {
            if ($version === $live || $version === $pin) {
                continue;
            }

            $this->remove($layout->version($version));
            $this->line('  removed '.$version);
            $removed++;
        }

        $this->info($removed === 0
            ? sprintf('Nothing to prune; %d version(s) installed.', count($installed))
            : sprintf('Pruned %d version(s).', $removed));

        return self::SUCCESS;
    }

    private function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach ((array) scandir($directory) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            is_dir($path) ? $this->remove($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
