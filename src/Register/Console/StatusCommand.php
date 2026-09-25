<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Console;

use Cbox\Tax\Register\Compile\SectionFetcher;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * What is installed, what is live, and what the register says it covers.
 *
 * Deliberately answerable OFFLINE. Everything but the "published" line comes off
 * local disk, so this still tells you what you are billing from when the network is
 * the thing that is broken.
 */
class StatusCommand extends Command
{
    protected $signature = 'tax:data:status {--offline : Do not ask the register what the latest release is}';

    protected $description = 'Show the installed Cbox Tax register and what it covers';

    public function handle(StoreLayout $layout, StorePointer $pointer, SectionFetcher $fetcher, RegisterDataset $dataset, Config $config): int
    {
        $live = $pointer->current();
        $pricing = $dataset->version();
        $pin = Shape::text($config->get('tax.register.version'));
        $installed = $layout->installed();

        $this->line('Store: '.$layout->root());

        if ($pricing === null) {
            $this->newLine();
            $this->warn($pin === null
                ? 'No register installed. Run `php artisan tax:data:sync`.'
                : sprintf('Pinned register %s is not installed. Run `php artisan tax:data:sync`.', $pin));

            return self::FAILURE;
        }

        $manifest = $this->manifest($layout->file($pricing, 'manifest.json'));

        $this->newLine();
        $this->table(['', ''], [
            ['Active version', $live ?? 'none'],
            ['Configured pin', $pin ?? 'none'],
            ['Pricing version', $pricing],
            ['Published at', Shape::scalar($manifest['publishedAt'] ?? '—')],
            ['Compiled at', Shape::scalar($manifest['compiledAt'] ?? '—')],
            ['Schema', Shape::scalar($manifest['schemaVersion'] ?? '—')],
            ['Regimes', $this->describe($manifest['regions'] ?? null, '—')],
            ['US states', $this->describe($manifest['states'] ?? null, 'all')],
            ['Street indexes', $this->describe($manifest['streets'] ?? null, 'none')],
            ['Files', (string) count(Shape::records($manifest['files'] ?? null))],
            ['Also installed', implode(', ', array_values(array_diff($installed, [$pricing]))) ?: '—'],
        ]);

        if (! $this->option('offline')) {
            $this->published($fetcher, $pricing, $pin);
        }

        $this->newLine();
        $this->comment('Licensed PolyForm Internal Use 1.0.0 — your own organisation, including commercially; no redistribution or resale.');

        return self::SUCCESS;
    }

    private function published(SectionFetcher $fetcher, string $live, ?string $pin): void
    {
        try {
            $latest = Shape::text($fetcher->json('/api/v1/releases/latest')['version'] ?? null);
        } catch (Throwable $e) {
            $this->newLine();
            $this->warn('Could not reach the register: '.$e->getMessage());

            return;
        }

        $this->newLine();

        if ($latest === $live) {
            $this->info('Up to date with the published register.');

            return;
        }

        $this->warn(sprintf('Published release: %s. %s', $latest ?? '?', $pin === null
            ? 'Run `php artisan tax:data:sync` to update.'
            : sprintf('Pricing is pinned to %s; change tax.register.version to update.', $pin)));
    }

    private function describe(mixed $value, string $whenNull): string
    {
        if ($value === null) {
            return $whenNull;
        }

        if (! is_array($value)) {
            return $whenNull;
        }

        return $value === [] ? $whenNull : implode(', ', array_map(Shape::scalar(...), $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $path): array
    {
        $raw = @file_get_contents($path);

        return Shape::map($raw === false ? null : json_decode($raw, true));
    }
}
