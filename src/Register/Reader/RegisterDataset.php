<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Contracts\TaxRegister;
use Cbox\Tax\Exceptions\DatasetNotInstalled;
use Cbox\Tax\Register\Store\ShardKey;
use Cbox\Tax\Register\Store\ShardReader;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;

/**
 * The typed way into a compiled store: what a jurisdiction charges, what rules scope
 * it, and what the register says about its own coverage.
 *
 * It reads local files and makes no network call. Everything it opens is opened
 * lazily and kept for the life of the object — a request that prices one line
 * usually prices the next one in the same jurisdiction, and the shard readers hold
 * an index, not records.
 *
 * The live version is resolved ONCE per instance. A sync that lands mid-request must
 * not move the answer under a half-priced invoice: two lines of one order have to be
 * priced by the same register, or the totals do not reconcile with either.
 *
 * THE FILE STORE, and the default binding of {@see RegisterData}. What a release means
 * is answered by {@see DocumentDataset}; this says where the compiled files are.
 *
 * @internal Changes with the store it reads. An application asks the register through
 *           {@see TaxRegister}, which does not.
 */
class RegisterDataset extends DocumentDataset
{
    private ?string $version = null;

    private bool $resolved = false;

    /** @var array<string, ShardReader> */
    private array $rates = [];

    /** @var array<string, ShardReader> */
    private array $jurisdictions = [];

    public function __construct(
        private readonly StoreLayout $layout,
        private readonly StorePointer $pointer,
        private readonly ?string $pinnedVersion = null,
    ) {}

    /** Where the store lives, for a refusal that can name it. */
    public function storeRoot(): string
    {
        return $this->layout->root();
    }

    /** The live version, pinned for the life of this instance. */
    public function version(): ?string
    {
        if (! $this->resolved) {
            $this->version = $this->pinnedVersion === null
                ? $this->pointer->current()
                : (is_file($this->layout->manifest($this->pinnedVersion)) ? $this->pinnedVersion : null);
            $this->resolved = true;
        }

        return $this->version;
    }

    /** The same store at another installed release; one that is not on disk refuses. */
    public function at(string $release): self
    {
        return new self($this->layout, $this->pointer, $release);
    }

    /**
     * Every rate record the register holds for a jurisdiction, unfiltered.
     *
     * Dating, category and taxType are the resolver's job, not this one's — a reader
     * that filtered would have to decide what "current" means, and the register is
     * explicit that the test is containment rather than a null end date.
     *
     * @return list<array<string, mixed>>
     */
    public function ratesFor(string $jurisdiction): array
    {
        $shard = ShardKey::of($jurisdiction);

        $this->rates[$shard] ??= new ShardReader(
            $this->layout->file($this->requireVersion(), 'rates/'.$shard),
        );

        return $this->rates[$shard]->read($jurisdiction);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function jurisdiction(string $code): ?array
    {
        $shard = ShardKey::of($code);

        $this->jurisdictions[$shard] ??= new ShardReader(
            $this->layout->file($this->requireVersion(), 'jurisdictions/'.$shard),
        );

        return $this->jurisdictions[$shard]->read($code)[0] ?? null;
    }

    /**
     * Every jurisdiction in a shard, as code => published name.
     *
     * Only useful where a shard is small and a NAME is the key a caller holds —
     * Florida's 67 counties, Virginia's 39 independent localities, Pennsylvania's
     * two, Hawaii's four. None of those states files a boundary artifact, so a name
     * is the only handle there is.
     *
     * @return array<string, string>
     */
    public function namesIn(string $shard): array
    {
        $reader = $this->jurisdictions[$shard] ??= new ShardReader(
            $this->layout->file($this->requireVersion(), 'jurisdictions/'.$shard),
        );

        $names = [];

        foreach ($reader->keys() as $code) {
            $name = Shape::text($reader->read($code)[0]['name'] ?? null);

            if ($name !== null) {
                $names[$code] = $name;
            }
        }

        return $names;
    }

    /**
     * Every shard this version was compiled with, from what is on disk.
     *
     * @return list<string>
     */
    public function shards(): array
    {
        $root = $this->layout->version($this->requireVersion());
        $found = [];

        foreach (['rates', 'jurisdictions'] as $kind) {
            foreach ($this->indexes($root.'/'.$kind, '') as $shard) {
                $found[$shard] = true;
            }
        }

        $shards = array_keys($found);
        sort($shards);

        return $shards;
    }

    /**
     * @return list<string>
     */
    public function codesIn(string $shard): array
    {
        $version = $this->requireVersion();

        $this->rates[$shard] ??= new ShardReader($this->layout->file($version, 'rates/'.$shard));
        $this->jurisdictions[$shard] ??= new ShardReader($this->layout->file($version, 'jurisdictions/'.$shard));

        $codes = [];

        foreach ([...$this->jurisdictions[$shard]->keys(), ...$this->rates[$shard]->keys()] as $code) {
            $codes[(string) $code] = true;
        }

        return array_map(strval(...), array_keys($codes));
    }

    public function boundaryPath(string $file): string
    {
        return $this->layout->file((string) $this->version(), 'boundaries/'.$file);
    }

    protected function notInstalled(): DatasetNotInstalled
    {
        return new DatasetNotInstalled($this->layout->root(), $this->pinnedVersion);
    }

    /** @return array<string, mixed> */
    protected function section(string $name): array
    {
        $raw = @file_get_contents($this->layout->file((string) $this->version(), $name.'.json'));
        $decoded = $raw === false ? null : json_decode($raw, true);

        /** @var array<string, mixed> $document */
        $document = is_array($decoded) ? $decoded : [];

        return $document;
    }

    /**
     * The shards indexed under a directory, by the name a lookup uses (`eu`, `us/TX`).
     *
     * @return list<string>
     */
    private function indexes(string $directory, string $prefix): array
    {
        $found = [];

        foreach ((array) @scandir($directory) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($directory.'/'.$entry)) {
                $found = [...$found, ...$this->indexes($directory.'/'.$entry, $prefix.$entry.'/')];

                continue;
            }

            if (str_ends_with($entry, '.idx')) {
                $found[] = $prefix.substr($entry, 0, -4);
            }
        }

        return $found;
    }
}
