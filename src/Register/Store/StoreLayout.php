<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Store;

/**
 * Where everything sits under the store root, and nothing else.
 *
 * One version per directory, and a `current` file naming the live one. That shape is
 * what makes a swap atomic: a new version is compiled into a directory nobody is
 * reading, and going live is one `rename` of a pointer file. A reader sees the old
 * version or the new one, never a half-written mixture — and the version it did not
 * switch to is still on disk, so going back is another rename rather than another
 * download.
 */
final readonly class StoreLayout
{
    public function __construct(private string $root) {}

    public function root(): string
    {
        return $this->root;
    }

    public function pointer(): string
    {
        return $this->root.'/current';
    }

    public function versions(): string
    {
        return $this->root.'/versions';
    }

    public function version(string $version): string
    {
        return $this->versions().'/'.$version;
    }

    /**
     * Where a version is BUILT, which is deliberately not where it is read from. A
     * directory named `.partial` cannot be mistaken for a version by
     * {@see installed()}, so an interrupted sync leaves rubbish that is visibly
     * rubbish rather than a version short a few shards.
     */
    public function partial(string $version): string
    {
        return $this->versions().'/'.$version.'.partial';
    }

    public function manifest(string $version): string
    {
        return $this->version($version).'/manifest.json';
    }

    public function file(string $version, string $relative): string
    {
        return $this->version($version).'/'.$relative;
    }

    /**
     * Every fully-installed version, newest name last.
     *
     * The register's versions sort correctly as strings — `2026.09.15-202` — because
     * the date leads and the counter is zero-free only after it. Where two disagree
     * the pointer decides; this is for listing and pruning, not for choosing.
     *
     * @return list<string>
     */
    public function installed(): array
    {
        $found = @scandir($this->versions());

        if ($found === false) {
            return [];
        }

        $versions = [];

        foreach ($found as $entry) {
            // `.partial` is a compile in flight; `.superseded-*` is the version a
            // re-sync moved aside and did not get to delete. BOTH CARRY A VALID
            // MANIFEST, so neither is excluded by the integrity check below — and a
            // leftover counted as installed is one `prune --keep=2` away from
            // deleting a real version to make room for a corpse.
            if ($entry === '.' || $entry === '..' || str_ends_with($entry, '.partial') || str_contains($entry, '.superseded-')) {
                continue;
            }

            if (is_file($this->manifest($entry))) {
                $versions[] = $entry;
            }
        }

        sort($versions);

        return $versions;
    }
}
