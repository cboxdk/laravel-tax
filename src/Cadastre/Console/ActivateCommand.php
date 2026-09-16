<?php

declare(strict_types=1);

namespace Cbox\Tax\Cadastre\Console;

use Cbox\Tax\Cadastre\Reader\Shape;
use Cbox\Tax\Cadastre\Store\StoreLayout;
use Cbox\Tax\Cadastre\Store\StorePointer;
use Illuminate\Console\Command;

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

    public function handle(StoreLayout $layout, StorePointer $pointer): int
    {
        $installed = $layout->installed();
        $wanted = Shape::scalar($this->argument('version'));

        if ($wanted === 'previous') {
            $live = $pointer->current();
            $others = array_values(array_diff($installed, $live === null ? [] : [$live]));
            $wanted = $others === [] ? '' : Shape::scalar(end($others));
        }

        if (! in_array($wanted, $installed, true)) {
            $this->error(sprintf('%s is not installed.', $wanted === '' ? 'No other version' : $wanted));
            $this->line('Installed: '.(implode(', ', $installed) ?: 'nothing'));

            return self::FAILURE;
        }

        $pointer->pointAt($wanted);
        $this->info(sprintf('Live: %s.', $wanted));

        return self::SUCCESS;
    }
}
