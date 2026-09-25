<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Store;

use Cbox\Tax\Exceptions\DatasetUnreadable;

/**
 * Reads one key's records out of a shard: decode the index once, seek, read, decode
 * the records and nothing else.
 *
 * The index is held for the life of the object because a request that asks about one
 * jurisdiction usually asks again — a line, then its delivery charge, then the next
 * line. The records are not held at all.
 */
class ShardReader
{
    /** @var array<string, list<array{int, int}>>|null */
    private ?array $index = null;

    /** @var resource|null */
    private mixed $handle = null;

    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path.'.idx');
    }

    public function has(string $key): bool
    {
        return isset($this->index()[$key]);
    }

    /**
     * Every record filed under the key, in the order the register published them.
     *
     * An unknown key is an empty list, which is the honest answer: this shard holds
     * nothing for that jurisdiction. Whether that means "no tax there" or "not
     * compiled" is a question about coverage, and the caller asks it of the manifest.
     *
     * @return list<array<string, mixed>>
     */
    public function read(string $key): array
    {
        $extents = $this->index()[$key] ?? [];

        if ($extents === []) {
            return [];
        }

        $handle = $this->handle();
        $records = [];

        foreach ($extents as [$offset, $length]) {
            if ($length < 1) {
                throw DatasetUnreadable::corruptShard($this->path, sprintf('the index claims a %d-byte record at offset %d', $length, $offset));
            }

            fseek($handle, $offset);
            $raw = fread($handle, $length);

            if ($raw === false) {
                throw DatasetUnreadable::corruptShard($this->path, sprintf('cannot read %d bytes at offset %d', $length, $offset));
            }

            $decoded = json_decode($raw, true);

            if (! is_array($decoded)) {
                throw DatasetUnreadable::corruptShard($this->path, sprintf('the record at offset %d does not decode', $offset));
            }

            /** @var array<string, mixed> $decoded */
            $records[] = $decoded;
        }

        return $records;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->index());
    }

    /**
     * @return array<string, list<array{int, int}>>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $raw = @file_get_contents($this->path.'.idx');

        if ($raw === false) {
            // A data file with no index beside it is a shard that was interrupted
            // between writing its records and writing where they are. Reading that
            // as an empty shard answers "this jurisdiction levies nothing", which is
            // a wrong answer wearing the shape of a right one.
            if (is_file($this->path)) {
                throw DatasetUnreadable::corruptShard($this->path, 'its index is missing');
            }

            return $this->index = [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw DatasetUnreadable::corruptShard($this->path, 'the index does not decode');
        }

        /** @var array<string, list<array{int, int}>> $decoded */
        return $this->index = $decoded;
    }

    /**
     * @return resource
     */
    private function handle(): mixed
    {
        if ($this->handle !== null) {
            return $this->handle;
        }

        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw DatasetUnreadable::corruptShard($this->path, 'the data file is missing while its index is not');
        }

        return $this->handle = $handle;
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }
}
