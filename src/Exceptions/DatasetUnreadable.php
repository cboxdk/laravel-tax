<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use RuntimeException;

/**
 * A register document or a compiled store file is not the shape it claims to be.
 *
 * Transient, and the retry is a person's: re-run `tax:data:sync`. A half-written
 * shard, a download cut off mid-record, a section that is not in the document at
 * all — every one of these is fixed by compiling the store again, and none of them
 * is fixed by asking a second time.
 *
 * It exists so that a bad read STOPS. The alternative, which this package refuses
 * everywhere, is a reader that skips what it cannot parse and returns a section
 * short by however many records happened to be malformed — indistinguishable from a
 * jurisdiction that genuinely publishes fewer.
 */
class DatasetUnreadable extends RuntimeException implements Transient
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function cannotOpen(string $path): self
    {
        return new self(sprintf('Cannot open "%s" for reading.', $path));
    }

    public static function noSuchSection(string $what, string $key, string $shape = 'array'): self
    {
        return new self(sprintf('No top-level %s "%s" in %s.', $shape, $key, $what));
    }

    public static function cannotInstall(string $path, string $because): self
    {
        return new self(sprintf('Cannot install the compiled register at "%s": %s. The store was left as it was; re-run `tax:data:sync`.', $path, $because));
    }

    public static function truncated(string $what, string $key): self
    {
        return new self(sprintf('The "%s" array in %s ends mid-record; the document is truncated.', $key, $what));
    }

    public static function unexpectedElement(string $what, string $key, string $found): self
    {
        return new self(sprintf('The "%s" array in %s holds a %s where an object was expected.', $key, $what, $found === '"' ? 'string' : 'scalar'));
    }

    public static function undecodableElement(string $what, string $key): self
    {
        return new self(sprintf('A record in the "%s" array of %s could not be decoded.', $key, $what));
    }

    public static function corruptShard(string $path, string $why): self
    {
        return new self(sprintf('The compiled shard "%s" is unusable: %s. Re-run `php artisan tax:data:sync`.', $path, $why));
    }
}
