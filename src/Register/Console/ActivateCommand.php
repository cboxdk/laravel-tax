<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Console;

use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Point the engine at a different installed version.
 *
 * The rollback, and it costs one rename: no download, no network, and it works when
 * the register is unreachable — which is exactly when a bad release is discovered.
 * A version stays on disk until pruned, so the previous one is normally right there.
 */
final class ActivateCommand extends Command
{
    protected $signature = 'tax:data:activate {version : An installed version, or "previous"}';

    protected $description = 'Make an already-installed register version live';

    public function handle(StoreLayout $layout, StorePointer $pointer, Config $config): int
    {
        $installed = $layout->installed();
        $wanted = Shape::scalar($this->argument('version'));

        if ($wanted === 'previous') {
            $live = $pointer->current();
            $others = array_values(array_filter(
                $installed,
                static fn (string $version): bool => $live !== null && strnatcmp($version, $live) < 0,
            ));
            $wanted = $others === [] ? '' : Shape::scalar(end($others));
        }

        if (! in_array($wanted, $installed, true)) {
            $this->error(sprintf('%s is not installed.', $wanted === '' ? 'No other version' : $wanted));
            $this->line('Installed: '.(implode(', ', $installed) ?: 'nothing'));

            return self::FAILURE;
        }

        $pointer->pointAt($wanted);
        $this->info(sprintf('Active: %s.', $wanted));

        $pin = Shape::text($config->get('tax.register.version'));

        if ($pin !== null && $pin !== $wanted) {
            $this->warn(sprintf('Pricing remains pinned to %s by tax.register.version.', $pin));
        }

        return self::SUCCESS;
    }
}
