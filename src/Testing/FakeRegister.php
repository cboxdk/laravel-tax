<?php

declare(strict_types=1);

namespace Cbox\Tax\Testing;

use Cbox\Tax\Register\Store\ShardKey;
use Cbox\Tax\Register\Store\ShardWriter;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;

/**
 * Builds a small register store on disk, for tests that need the engine to have
 * data without downloading 63 MB of it.
 *
 * SHIPPED, because a host testing its own tax logic has the same problem this suite
 * does: the engine refuses without a store, and nobody wants a network call in a
 * unit test. Build the three rates the test is actually about and go.
 *
 * WHAT IT IS NOT FOR. A store written here contains whatever the test said, which
 * makes it perfect for mechanics — dating, ladders, shard reads, refusals — and
 * worthless as evidence about a rate. That distinction is not pedantry: every
 * fixture in the shared resolver package was hand-built to match the parser's
 * assumption rather than the publisher's bytes, so a format change went unnoticed
 * for months while the suite stayed green and the answer was empty. Assertions
 * about what a jurisdiction actually charges belong against the live register, in
 * a group that is skipped when it is unreachable.
 */
final class FakeRegister
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $rates = [];

    /** @var list<array<string, mixed>> */
    private array $rules = [];

    /** @var array<string, string> */
    private array $names = [];

    /** @var list<array<string, mixed>> */
    private array $categories = [];

    private function __construct(
        private readonly StoreLayout $layout,
        private readonly string $version,
    ) {}

    public static function at(string $root, string $version = '2026.01.01-1'): self
    {
        return new self(new StoreLayout($root), $version);
    }

    /**
     * A rate, with the fields the resolver reads and sensible defaults for the rest.
     *
     * `borneBy` defaults to `customer` because that is what a test means by "a rate";
     * a supplier-borne levy has to be asked for explicitly, which is the right way
     * round — it is the case that must never be summed into a cart by accident.
     *
     * @param  array<string, mixed>  $extra
     */
    public function rate(
        string $jurisdiction,
        string $percentage,
        string $kind = 'standard',
        ?string $category = null,
        ?string $from = null,
        ?string $until = null,
        ?string $classification = null,
        array $extra = [],
    ): self {
        $this->rates[$jurisdiction][] = [
            'jurisdiction' => $jurisdiction,
            'taxType' => 'vat',
            'borneBy' => 'customer',
            'kind' => $kind,
            'basis' => 'ad_valorem',
            'percentage' => $percentage,
            'effective' => ['from' => $from, 'until' => $until, 'startIsFloor' => false],
            'classification' => $classification === null ? null : ['scheme' => 'cn', 'code' => $classification, 'label' => null],
            'category' => $category,
            'provenance' => ['source' => 'fake', 'snapshot' => str_repeat('0', 64), 'capturedAt' => '2026-01-01T00:00:00+00:00', 'note' => null],
            ...$extra,
        ];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function rule(string $jurisdiction, string $kind, array $payload, ?string $from = null, ?string $until = null): self
    {
        $this->rules[] = [
            'jurisdiction' => $jurisdiction,
            'kind' => $kind,
            'effective' => ['from' => $from, 'until' => $until, 'startIsFloor' => false],
            'payload' => $payload,
            'provenance' => ['source' => 'fake', 'snapshot' => str_repeat('0', 64), 'capturedAt' => '2026-01-01T00:00:00+00:00', 'note' => null],
        ];

        return $this;
    }

    public function named(string $jurisdiction, string $name): self
    {
        $this->names[$jurisdiction] = $name;

        return $this;
    }

    public function category(string $key, ?string $parent = null): self
    {
        $this->categories[] = ['key' => $key, 'name' => $key, 'parent' => $parent, 'description' => null, 'regions' => [], 'cites' => null];

        return $this;
    }

    /**
     * Write the store and point the engine at it. Returns the version.
     */
    public function install(): string
    {
        $directory = $this->layout->version($this->version);
        $regions = [];
        $writers = [];
        $jurisdictions = [];

        foreach ($this->rates as $code => $records) {
            $regions[ShardKey::regime($code)] = true;
            $shard = ShardKey::of($code);

            $writers[$shard] ??= new ShardWriter($directory.'/rates/'.$shard);

            foreach ($records as $record) {
                $writers[$shard]->append($code, $record);
            }

            $jurisdictions[$shard] ??= new ShardWriter($directory.'/jurisdictions/'.$shard);
            $jurisdictions[$shard]->append($code, [
                'code' => $code,
                'name' => $this->names[$code] ?? $code,
                'level' => 'country',
                'parent' => null,
                'inTaxArea' => true,
            ]);
        }

        foreach ($writers as $writer) {
            $writer->close();
        }

        foreach ($jurisdictions as $writer) {
            $writer->close();
        }

        $this->put($directory, 'rules.json', ['rules' => $this->rules]);
        $this->put($directory, 'standard-rates.json', ['standardRates' => []]);
        $this->put($directory, 'coverage.json', ['version' => $this->version, 'summary' => []]);
        $this->put($directory, 'meta.json', [
            'regimes' => $this->regimes(),
            'categories' => $this->categories,
            'mappings' => [],
            'sources' => [],
        ]);
        $this->put($directory, 'manifest.json', [
            'version' => $this->version,
            'schemaVersion' => '1.33.0',
            'regions' => array_keys($regions),
            'states' => null,
            'streets' => [],
            'files' => [],
        ]);

        new StorePointer($this->layout)->pointAt($this->version);

        return $this->version;
    }

    /**
     * Regimes derived from what was added, so `codesForCountry()` resolves without
     * the test having to describe the world twice.
     *
     * @return list<array<string, mixed>>
     */
    private function regimes(): array
    {
        $members = [];

        foreach (array_keys($this->rates) as $code) {
            $parts = explode(':', $code);

            if (count($parts) < 2) {
                continue;
            }

            $members[$parts[0]][] = [
                'code' => $parts[0].':'.$parts[1],
                'name' => $this->names[$parts[0].':'.$parts[1]] ?? $parts[1],
                'isoCode' => $parts[1],
                'from' => null,
                'until' => null,
                'leviesNoTax' => false,
            ];
        }

        $regimes = [];

        foreach ($members as $regime => $list) {
            $seen = [];
            $unique = [];

            foreach ($list as $member) {
                if (! isset($seen[$member['code']])) {
                    $seen[$member['code']] = true;
                    $unique[] = $member;
                }
            }

            $regimes[] = ['regime' => $regime, 'members' => $unique];
        }

        return $regimes;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function put(string $directory, string $name, array $payload): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        file_put_contents($directory.'/'.$name, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
