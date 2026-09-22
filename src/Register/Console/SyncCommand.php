<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Console;

use Cbox\Tax\Register\Compile\Compiler;
use Cbox\Tax\Register\Compile\SectionFetcher;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Compile a published release into the local store and make it live.
 *
 * Downloads and compilation happen here. Pricing reads local files.
 */
final class SyncCommand extends Command
{
    protected $signature = 'tax:data:sync
        {--release= : A release to compile, or "latest"; defaults to tax.register.version or latest}
        {--region=* : Limit to these regimes (eu, us, apac, …); overrides tax.register.regions}
        {--state=* : Limit the US to these states; overrides tax.register.states}
        {--streets=* : US states to also fetch the street index for — 228 MB across fifteen states, one rung finer than a ZIP+4}
        {--no-boundaries : Skip the postal and polygon boundary artifacts entirely}
        {--check : Say whether the installed version is behind and exit non-zero if it is, without downloading anything}
        {--keep= : Installed versions to retain afterwards; defaults to tax.register.keep}';

    protected $description = 'Compile the Cbox Tax register into the local store';

    public function handle(SectionFetcher $fetcher, StoreLayout $layout, StorePointer $pointer, RegisterDataset $dataset, Config $config): int
    {
        $pin = Shape::text($config->get('tax.register.version'));
        $release = Shape::text($this->option('release')) ?? $pin ?? 'latest';

        if ($this->option('check')) {
            return $this->check($fetcher, $dataset, $release);
        }

        $regions = $this->list('region', $config->get('tax.register.regions'));
        $states = $this->list('state', $config->get('tax.register.states'));
        $streets = $this->list('streets', $config->get('tax.register.streets')) ?? [];

        $compiler = new Compiler($fetcher, $layout);

        $result = $compiler->compile(
            $release,
            $regions,
            $states,
            ! $this->option('no-boundaries') && $config->get('tax.register.boundaries', true) === true,
            $streets,
            fn (string $line): null => $this->line($line),
        );

        $pointer->pointAt($result['version']);

        $this->newLine();
        $this->info(sprintf(
            'Active: %s — %d files, %s MB on disk.',
            $result['version'],
            $result['files'],
            number_format($result['bytes'] / 1048576, 1),
        ));

        if ($pin !== null && $pin !== $result['version']) {
            $this->warn(sprintf('Pricing remains pinned to %s by tax.register.version.', $pin));
        }

        $keep = Shape::text($this->option('keep'));
        $this->call('tax:data:prune', $keep === null ? [] : ['--keep' => $keep]);

        $this->comment('The register is licensed PolyForm Internal Use 1.0.0: use it inside your own organisation for anything, including commercially. Do not redistribute it or resell lookups from it.');

        return self::SUCCESS;
    }

    /**
     * Compare what is installed against what is published, for a few kilobytes.
     *
     * Exits non-zero when behind, so a deploy step or a cron can gate on it without
     * pulling the register to find out.
     */
    private function check(SectionFetcher $fetcher, RegisterDataset $dataset, string $release): int
    {
        $installed = $dataset->version();
        $wanted = $fetcher->resolve($release);

        if ($installed === null) {
            $this->warn(sprintf('No register available for pricing. Required release is %s.', $wanted));

            return self::FAILURE;
        }

        if ($installed === $wanted) {
            $this->info(sprintf('Up to date: %s.', $installed));

            return self::SUCCESS;
        }

        $this->warn(sprintf('Update needed: pricing uses %s, requested release is %s.', $installed, $wanted));

        return self::FAILURE;
    }

    /**
     * @return list<string>|null
     */
    private function list(string $option, mixed $configured): ?array
    {
        $values = $this->option($option);

        if (! is_array($values) || $values === []) {
            $values = is_array($configured) ? $configured : [Shape::scalar($configured)];
        }

        $out = [];

        foreach ($values as $value) {
            foreach (explode(',', Shape::scalar($value)) as $item) {
                $item = trim($item);

                if ($item !== '') {
                    $out[] = $option === 'region' ? strtolower($item) : strtoupper($item);
                }
            }
        }

        return $out === [] ? null : array_values(array_unique($out));
    }
}
