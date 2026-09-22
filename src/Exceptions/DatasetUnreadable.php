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

    /**
     * The release is built to a schema this package has not been reviewed against.
     *
     * Kept apart from {@see self::corruptShard()} because the two need opposite advice:
     * a corrupt shard is fixed by compiling again, and this is not — telling an
     * operator to re-run a command that will fail the same way every time is worse
     * than saying nothing. Raised before compiling (nothing is written) and before
     * reading a store another worker installed (nothing is priced from it).
     */
    public static function unsupportedSchema(string $version, ?string $declared, string $supported): self
    {
        return new self(sprintf(
            'Release %s has unsupported schemaVersion %s; this package reads %s, so the release is not used. '
            .'Pin a release on a supported schema with `tax:data:sync --release=<version>`, or upgrade cboxdk/laravel-tax.',
            $version,
            $declared ?? '(none)',
            $supported,
        ));
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
