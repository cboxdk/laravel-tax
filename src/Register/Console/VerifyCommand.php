<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Console;

use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\Register\Store\StoreLayout;
use Illuminate\Console\Command;

/** Checks the installed files against the manifest written during compilation. */
class VerifyCommand extends Command
{
    protected $signature = 'tax:data:verify {release? : Installed release; defaults to the version used for pricing}';

    protected $description = 'Verify the local register files against their compiled manifest, without network access';

    public function handle(StoreLayout $layout, RegisterDataset $dataset): int
    {
        $version = Shape::text($this->argument('release')) ?? $dataset->version();

        if ($version === null || ! in_array($version, $layout->installed(), true)) {
            $this->error('The requested register is not installed. Run `php artisan tax:data:sync`.');

            return self::FAILURE;
        }

        $raw = @file_get_contents($layout->manifest($version));
        $manifest = Shape::map($raw === false ? null : json_decode($raw, true));
        $files = $manifest['files'] ?? null;

        if (($manifest['version'] ?? null) !== $version || ! is_array($files) || ! array_is_list($files) || $files === []) {
            $this->error('The compiled manifest is invalid or contains no files. Re-run `php artisan tax:data:sync`.');

            return self::FAILURE;
        }

        $root = realpath($layout->version($version));
        $seen = [];
        $failed = false;

        foreach ($files as $file) {
            $entry = Shape::map($file);
            $path = Shape::text($entry['path'] ?? null);
            $hash = Shape::text($entry['sha256'] ?? null);
            $bytes = $entry['bytes'] ?? null;

            if ($path === null || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")
                || in_array('..', explode('/', $path), true) || isset($seen[$path])
                || $hash === null || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
                || ! is_int($bytes) || $bytes < 0) {
                $this->error('Invalid manifest entry: '.($path ?? '(missing path)'));
                $failed = true;

                continue;
            }

            $seen[$path] = true;
            $absolute = realpath($layout->file($version, $path));

            if ($root === false || $absolute === false || ! str_starts_with($absolute, $root.DIRECTORY_SEPARATOR) || ! is_file($absolute)) {
                $this->error('Missing or invalid file: '.$path);
                $failed = true;

                continue;
            }

            if (@filesize($absolute) !== $bytes || @hash_file('sha256', $absolute) !== $hash) {
                $this->error('Integrity check failed: '.$path);
                $failed = true;
            }
        }

        if ($failed) {
            $this->error('Verification failed. Re-run `php artisan tax:data:sync`.');

            return self::FAILURE;
        }

        $this->info(sprintf('Verified %s: %d files.', $version, count($files)));

        return self::SUCCESS;
    }
}
