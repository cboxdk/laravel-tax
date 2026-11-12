<?php

declare(strict_types=1);

namespace Cbox\Tax\Tests\Fixtures;

use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Register\Reader\DocumentDataset;

/**
 * A second implementation of the dataset contract, for the suite: a release held in
 * memory, copied out of another dataset.
 *
 * It stands in for what a host writes when the register is kept somewhere other than
 * the file store. It reads no shard and no section file of its own; only the boundary
 * files are still files, as the contract says they are.
 */
final class CopiedDataset extends DocumentDataset
{
    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @param  array<string, list<string>>  $codes  shard => codes
     * @param  array<string, list<array<string, mixed>>>  $rates
     * @param  array<string, array<string, mixed>>  $jurisdictions
     */
    private function __construct(
        private readonly ?string $release,
        private readonly array $sections,
        private readonly array $codes,
        private readonly array $rates,
        private readonly array $jurisdictions,
        private readonly ?RegisterData $boundaries,
    ) {}

    public static function of(RegisterData $source): self
    {
        $sections = [];

        foreach (['manifest', 'meta', 'rules', 'standard-rates', 'coverage', 'us-local', 'facts'] as $name) {
            $sections[$name] = $source->document($name);
        }

        $codes = [];
        $rates = [];
        $jurisdictions = [];

        foreach ($source->shards() as $shard) {
            $codes[$shard] = $source->codesIn($shard);

            foreach ($codes[$shard] as $code) {
                $rates[$code] = $source->ratesFor($code);
                $record = $source->jurisdiction($code);

                if ($record !== null) {
                    $jurisdictions[$code] = $record;
                }
            }
        }

        return new self($source->requireVersion(), $sections, $codes, $rates, $jurisdictions, $source);
    }

    public function version(): ?string
    {
        return $this->release;
    }

    public function storeRoot(): string
    {
        return 'memory';
    }

    public function at(string $release): self
    {
        return $release === $this->release ? $this : new self(null, [], [], [], [], null);
    }

    public function shards(): array
    {
        return array_keys($this->codes);
    }

    public function codesIn(string $shard): array
    {
        return $this->codes[$shard] ?? [];
    }

    public function ratesFor(string $jurisdiction): array
    {
        return $this->rates[$jurisdiction] ?? [];
    }

    public function jurisdiction(string $code): ?array
    {
        return $this->jurisdictions[$code] ?? null;
    }

    public function boundaryPath(string $file): string
    {
        return $this->boundaries?->boundaryPath($file) ?? sys_get_temp_dir().'/cbox-tax-no-boundaries/'.$file;
    }

    protected function section(string $name): array
    {
        return $this->sections[$name] ?? [];
    }
}
