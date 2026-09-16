<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Store;

use Cbox\Tax\Exceptions\DatasetUnreadable;

/**
 * Writes one shard: records as newline-delimited JSON, plus an index saying where
 * each key's records begin and how long they are.
 *
 * The shape exists so a lookup costs a seek instead of a decode. Washington carries
 * 31 086 rate records across 1 490 jurisdictions — 13.4 MB — and pricing one address
 * there needs about twenty of them. Reading the shard as a document would decode all
 * 13.4 MB to answer a question about 9 KB of it, and that is before Texas, Kansas
 * and Missouri. With an index, the reader touches the index (54 KB at worst in the
 * whole register) and the record, and nothing else.
 *
 * A key holds a LIST of extents rather than one, so records need not arrive grouped.
 * The register does not promise an order, and buffering by key to impose one would
 * put the whole section back in memory — which is the thing the streaming reader
 * exists to avoid.
 */
final class ShardWriter
{
    /** @var resource|null */
    private mixed $handle = null;

    /** @var array<string, list<array{int, int}>> */
    private array $index = [];

    private int $offset = 0;

    public function __construct(private readonly string $path) {}

    /**
     * @param  array<string, mixed>|list<mixed>  $record
     */
    public function append(string $key, array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            // Dropping it would make the shard short by one record and say nothing.
            throw DatasetUnreadable::corruptShard($this->path, sprintf('the record for "%s" cannot be encoded', $key));
        }

        fwrite($this->open(), $line."\n");

        $length = strlen($line);
        $this->index[$key][] = [$this->offset, $length];
        $this->offset += $length + 1;
    }

    public function isEmpty(): bool
    {
        return $this->index === [];
    }

    /**
     * Flush the data file and write the index beside it.
     *
     * The index is written LAST and only on a clean close, so a shard whose index is
     * missing is visibly incomplete rather than quietly short.
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        if ($this->index === []) {
            return;
        }

        file_put_contents(
            $this->path.'.idx',
            json_encode($this->index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return resource
     */
    private function open(): mixed
    {
        if ($this->handle !== null) {
            return $this->handle;
        }

        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $handle = @fopen($this->path, 'wb');

        if ($handle === false) {
            throw DatasetUnreadable::corruptShard($this->path, 'it cannot be opened for writing');
        }

        return $this->handle = $handle;
    }
}
