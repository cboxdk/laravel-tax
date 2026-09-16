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

    /** @var array<string, array{sets: list<list<array<string, string>>>, zip: array<string, list<array{0: string, 1: string, 2: int}>>}> */
    private array $boundaries = [];

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
     * `mayBePassedOn` is written even though the default never changes the answer,
     * because the register always carries it and the pair is what decides whether a
     * rate can be charged. Pass `['borneBy' => 'supplier', 'mayBePassedOn' => true]`
     * for a transaction privilege or gross receipts tax — Arizona, Hawaii and New
     * Mexico are all that shape — and `false` for a digital services tax.
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
            'mayBePassedOn' => false,
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

    /**
     * A ZIP that resolves to a set of authorities, written as a formatVersion 3
     * boundary artifact.
     *
     * Authorities are given as `level:code` — `county:209`, `city:36000` — and the
     * STATE is one of them where the state's own rate applies there. That is the
     * format's own shape: the boundary file says whether the state share is due,
     * rather than a consumer adding it on top.
     *
     * @param  list<string>  $authorities
     */
    public function boundary(string $state, string $zip5, array $authorities, string $from = '0000', string $to = '9999'): self
    {
        $set = [];

        foreach ($authorities as $authority) {
            [$level, $code] = array_pad(explode(':', $authority, 2), 2, '');
            $set[] = ['level' => $level, 'code' => $code];
        }

        $this->boundaries[$state] ??= ['sets' => [], 'zip' => []];
        $this->boundaries[$state]['zip'][$zip5] ??= [];
        $this->boundaries[$state]['sets'][] = $set;
        $index = count($this->boundaries[$state]['sets']) - 1;
        // Narrowest-first, because the format says first match wins and the order is
        // the whole difference between one county and another inside one ZIP.
        array_unshift($this->boundaries[$state]['zip'][$zip5], [$from, $to, $index]);

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
     * The level the register would publish for a code.
     *
     * `us:CA` is a state and `ca:CA` is a country, and telling them apart is the
     * whole reason this field exists — see the note on members, above.
     */
    private function levelOf(string $code): string
    {
        $parts = explode(':', $code);

        if (count($parts) > 2) {
            return str_starts_with($parts[2], 'COUNTY-') ? 'county' : 'city';
        }

        return $parts[0] === 'us' ? 'state' : 'country';
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
                'level' => $this->levelOf($code),
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

        foreach ($this->boundaries as $state => $artifact) {
            $this->put($directory, 'boundaries/'.$state.'.zip.json', [
                'formatVersion' => 3,
                'version' => $this->version,
                'state' => $state,
                'provenance' => ['sourceKey' => 'fake', 'snapshotHash' => str_repeat('0', 64), 'capturedAt' => '2026-01-01T00:00:00+00:00'],
                'sets' => $artifact['sets'],
                'zip' => $artifact['zip'],
                'ranges' => [],
            ]);
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

            // US STATES ARE LISTED, because the register lists them — all fifty-four
            // of them, `us:CA` among them. Leaving them out here made this double
            // kinder than the thing it doubles: `us:CA` ends in the same two letters
            // as Canada, and the engine picked it for a Canadian supply for as long
            // as this fixture hid the collision. What keeps them apart is the `level`
            // on the jurisdiction, which is the register's own signal, so that is
            // what the fixture must reproduce.

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
        $path = $directory.'/'.$name;

        // The file's OWN directory, not the version root — `boundaries/KS.zip.json`
        // lives a level down and silently wrote nothing when only the root existed.
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }

        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
