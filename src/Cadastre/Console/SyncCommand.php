<?php

declare(strict_types=1);

namespace Cbox\Tax\Cadastre\Console;

use Cbox\Tax\Cadastre\Compile\Compiler;
use Cbox\Tax\Cadastre\Compile\SectionFetcher;
use Cbox\Tax\Cadastre\Reader\Shape;
use Cbox\Tax\Cadastre\Store\StoreLayout;
use Cbox\Tax\Cadastre\Store\StorePointer;
use Illuminate\Console\Command;

/**
 * Compile a published release into the local store and make it live.
 *
 * This is the only thing in the package that talks to the network, and it is the one
 * command a deployment needs. Pricing reads local files.
 */
final class SyncCommand extends Command
{
    protected $signature = 'tax:data:sync
        {--release=latest : A release to compile, or "latest". Not --version, which Symfony Console owns}
        {--region=* : Limit to these regimes (eu, us, apac, …). Default: every one the release carries}
        {--state=* : Limit the US to these states. Default: all of them}
        {--streets=* : US states to also fetch the street index for — 228 MB across fifteen states, one rung finer than a ZIP+4}
        {--no-boundaries : Skip the postal and polygon boundary artifacts entirely}
        {--check : Say whether the installed version is behind and exit non-zero if it is, without downloading anything}
        {--keep= : Installed versions to retain afterwards}';

    protected $description = 'Compile the Cbox Tax register into the local store';

    public function handle(SectionFetcher $fetcher, StoreLayout $layout, StorePointer $pointer): int
    {
        if ($this->option('check')) {
            return $this->check($fetcher, $pointer);
        }

        $regions = $this->list('region');
        $states = $this->list('state');
        $streets = $this->list('streets') ?? [];

        $compiler = new Compiler($fetcher, $layout);

        $result = $compiler->compile(
            Shape::text($this->option('release')) ?? 'latest',
            $regions,
            $states,
            ! $this->option('no-boundaries'),
            array_map(strtoupper(...), $streets),
            fn (string $line): null => $this->line($line),
        );

        $pointer->pointAt($result['version']);

        $this->newLine();
        $this->info(sprintf(
            'Live: %s — %d files, %s MB on disk.',
            $result['version'],
            $result['files'],
            number_format($result['bytes'] / 1048576, 1),
        ));

        $keep = Shape::text($this->option('keep'));

        if ($keep !== null) {
            $this->call('tax:data:prune', ['--keep' => $keep]);
        }

        $this->comment('The register is licensed PolyForm Internal Use 1.0.0: use it inside your own organisation for anything, including commercially. Do not redistribute it or resell lookups from it.');

        return self::SUCCESS;
    }

    /**
     * Compare what is installed against what is published, for a few kilobytes.
     *
     * Exits non-zero when behind, so a deploy step or a cron can gate on it without
     * pulling the register to find out.
     */
    private function check(SectionFetcher $fetcher, StorePointer $pointer): int
    {
        $installed = $pointer->current();
        $latest = Shape::text($fetcher->json('/api/v1/releases/latest')['version'] ?? null);

        if ($latest === null) {
            $this->error('The register did not name a latest release.');

            return self::FAILURE;
        }

        if ($installed === null) {
            $this->warn(sprintf('Nothing installed. Latest published release is %s.', $latest));

            return self::FAILURE;
        }

        if ($installed === $latest) {
            $this->info(sprintf('Up to date: %s.', $installed));

            return self::SUCCESS;
        }

        $this->warn(sprintf('Behind: %s installed, %s published.', $installed, $latest));

        return self::FAILURE;
    }

    /**
     * @return list<string>|null
     */
    private function list(string $option): ?array
    {
        $values = $this->option($option);

        if (! is_array($values) || $values === []) {
            return null;
        }

        $out = [];

        foreach ($values as $value) {
            foreach (explode(',', Shape::scalar($value)) as $item) {
                $item = trim($item);

                if ($item !== '') {
                    $out[] = $item;
                }
            }
        }

        return $out === [] ? null : $out;
    }
}
