<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Store;

/**
 * Which installed version is live, read and written atomically.
 *
 * The write is `tmp + rename`, because `rename` within a filesystem is atomic and a
 * plain `file_put_contents` is not: a reader that opens the pointer while it is
 * being written gets a truncated version string, which resolves to a directory that
 * does not exist. Rare, and rare is worse than never — it would happen during a
 * deploy, under load, and look like a corrupt store.
 */
final readonly class StorePointer
{
    public function __construct(private StoreLayout $layout) {}

    public function current(): ?string
    {
        $raw = @file_get_contents($this->layout->pointer());

        if ($raw === false) {
            return null;
        }

        $version = trim($raw);

        // A pointer at a version that is no longer installed is not a live store.
        // Saying so is better than resolving paths under a directory that is gone.
        return $version !== '' && is_file($this->layout->manifest($version)) ? $version : null;
    }

    public function pointAt(string $version): void
    {
        $pointer = $this->layout->pointer();
        $temporary = $pointer.'.'.bin2hex(random_bytes(6));

        if (! is_dir(dirname($pointer))) {
            mkdir(dirname($pointer), 0o775, true);
        }

        file_put_contents($temporary, $version."\n");
        rename($temporary, $pointer);
    }
}
