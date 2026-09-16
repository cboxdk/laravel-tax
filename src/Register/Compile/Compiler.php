<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Compile;

use Cbox\Tax\Exceptions\DatasetUnreadable;
use Cbox\Tax\Register\Store\ShardWriter;
use Cbox\Tax\Register\Store\StoreLayout;
use Closure;

/**
 * Turns a published release into a local store the engine can read a record at a
 * time.
 *
 * The store is NOT a copy of what the API returns. The register is published as
 * documents — 48.8 MB for the US alone — and a document is the one shape a PHP
 * process cannot hold. What is written here is the same facts, sharded by the thing
 * a lookup actually names (a regime, or a US state) and indexed by jurisdiction, so
 * pricing an address touches a 54 KB index and one record instead of a region.
 *
 * Everything large is streamed: downloaded to disk, walked by {@see JsonArrayStream},
 * written straight into a shard. Peak memory is one record, and the biggest record
 * in the register is 3 922 bytes.
 *
 * It compiles into `<version>.partial` and renames only when the manifest is written,
 * so an interrupted sync leaves a directory that is visibly unfinished rather than a
 * version quietly missing three shards.
 */
final readonly class Compiler
{
    /** The register schema this compiler was written against. */
    private const string SCHEMA_MAJOR = '1';

    public function __construct(
        private SectionFetcher $fetcher,
        private StoreLayout $layout,
    ) {}

    /**
     * @param  list<string>|null  $regions  null compiles every regime the release carries
     * @param  list<string>|null  $states  null compiles every US state
     * @param  list<string>  $streets  US states to also pull the street index for
     * @param  Closure(string): void  $say
     * @return array{version: string, bytes: int, files: int}
     */
    public function compile(
        string $version,
        ?array $regions,
        ?array $states,
        bool $boundaries,
        array $streets,
        Closure $say,
    ): array {
        $version = $this->fetcher->resolve($version);
        $release = $this->fetcher->json('/api/v1/releases/'.$version);

        $this->assertSchema($release, $version);

        $partial = $this->layout->partial($version);
        $this->reset($partial);
        mkdir($partial, 0o775, true);

        $say(sprintf('Compiling release %s', $version));

        $base = '/api/v1/releases/'.$version;

        // The small, always-useful sections, written as they arrive. Together these
        // are under 200 KB and every one of them is needed to read a rate: the
        // vocabulary a category is named in, the mappings a catalogue reaches it by,
        // and the rules that scope it.
        $this->put($partial, 'meta.json', [
            'regimes' => $this->fetcher->json("{$base}/sections/regimes")['regimes'] ?? [],
            'categories' => $this->fetcher->json("{$base}/sections/categories")['categories'] ?? [],
            'mappings' => $this->fetcher->json("{$base}/sections/mappings")['mappings'] ?? [],
            'sources' => $this->fetcher->json("{$base}/sections/sources")['sources'] ?? [],
        ]);
        $say('  meta — regimes, categories, mappings, sources');

        $this->put($partial, 'rules.json', $this->fetcher->json("{$base}/sections/rules"));
        $this->put($partial, 'coverage.json', $this->fetcher->json("{$base}/coverage"));
        $this->put($partial, 'standard-rates.json', $this->fetcher->json("{$base}/sections/standard-rates"));
        $say('  rules, coverage, standard rates');

        $wanted = $this->regionsOf($release, $regions);

        foreach ($wanted as $region) {
            $this->compileRegion($base, $partial, $region, $states, $say);
        }

        if ($boundaries) {
            $this->compileBoundaries($base, $partial, $states, $streets, $say);
        }

        $files = $this->manifest($partial, $version, $release, $wanted, $states, $streets);

        $final = $this->layout->version($version);
        $this->reset($final);
        rename($partial, $final);

        return [
            'version' => $version,
            'bytes' => array_sum(array_map(static fn (array $f): int => $f['bytes'], $files)),
            'files' => count($files),
        ];
    }

    /**
     * One regime's rates and jurisdictions, streamed out of its region document.
     *
     * US rates are sharded PER STATE because the region is 38.3 MB of them and
     * Washington alone is 13.4 MB — a shop selling only into Texas has no use for
     * it. Every other regime is one shard, because all ten together are 4 MB.
     *
     * @param  list<string>|null  $states
     * @param  Closure(string): void  $say
     */
    private function compileRegion(string $base, string $partial, string $region, ?array $states, Closure $say): void
    {
        $document = $partial.'/.download/'.$region.'.json';
        $bytes = $this->fetcher->download("{$base}/regions/{$region}", $document);

        if ($bytes === null) {
            $say(sprintf('  %s — not in this release', $region));

            return;
        }

        /** @var array<string, ShardWriter> $rates */
        $rates = [];
        /** @var array<string, ShardWriter> $jurisdictions */
        $jurisdictions = [];
        $kept = 0;
        $skipped = 0;

        foreach (JsonArrayStream::fromFile($document, 'rates') as $rate) {
            $code = $rate['jurisdiction'] ?? null;

            if (! is_string($code)) {
                continue;
            }

            $shard = $this->shardFor($region, $code, $states);

            if ($shard === null) {
                $skipped++;

                continue;
            }

            $rates[$shard] ??= new ShardWriter($partial.'/rates/'.$shard);
            $rates[$shard]->append($code, $rate);
            $kept++;
        }

        foreach (JsonArrayStream::fromFile($document, 'jurisdictions') as $jurisdiction) {
            $code = $jurisdiction['code'] ?? null;

            if (! is_string($code)) {
                continue;
            }

            $shard = $this->shardFor($region, $code, $states);

            if ($shard === null) {
                continue;
            }

            $jurisdictions[$shard] ??= new ShardWriter($partial.'/jurisdictions/'.$shard);
            $jurisdictions[$shard]->append($code, $jurisdiction);
        }

        // Closed in two loops, NOT via `[...$rates, ...$jurisdictions]`. Both arrays
        // are keyed by shard name, and spreading string-keyed arrays merges by key —
        // so the jurisdictions writer replaced the rates writer of the same name and
        // the rates index was silently never written. The shard then read as empty,
        // which is the one failure mode this package must never produce quietly.
        foreach ($rates as $writer) {
            $writer->close();
        }

        foreach ($jurisdictions as $writer) {
            $writer->close();
        }

        @unlink($document);

        $say(sprintf(
            '  %s — %s rates in %d shard(s)%s',
            $region,
            number_format($kept),
            count($rates),
            $skipped > 0 ? sprintf(', %s skipped (states not requested)', number_format($skipped)) : '',
        ));
    }

    /**
     * Which shard a jurisdiction's records belong in, or null when the caller did
     * not ask for that state.
     *
     * @param  list<string>|null  $states
     */
    private function shardFor(string $region, string $code, ?array $states): ?string
    {
        if ($region !== 'us') {
            return $region;
        }

        $parts = explode(':', $code);
        $state = $parts[1] ?? null;

        if ($state === null) {
            // `us` with no segments is the regime itself. It rides with the federal
            // shard rather than being dropped: a rule can hang off it.
            return 'us/_US';
        }

        if ($states !== null && ! in_array($state, $states, true)) {
            return null;
        }

        return 'us/'.$state;
    }

    /**
     * @param  list<string>|null  $states
     * @param  list<string>  $streets
     * @param  Closure(string): void  $say
     */
    private function compileBoundaries(string $base, string $partial, ?array $states, array $streets, Closure $say): void
    {
        $listing = $this->fetcher->json("{$base}/boundaries");
        $byState = $listing['states'] ?? null;

        if (! is_array($byState)) {
            $say('  boundaries — this release publishes none');

            return;
        }

        $zip = 0;
        $geo = 0;
        $street = 0;

        foreach ($byState as $state => $artifacts) {
            $state = (string) $state;

            if (! is_array($artifacts) || ($states !== null && ! in_array($state, $states, true))) {
                continue;
            }

            if ($this->boundaryArtifact($artifacts, 'zip', $partial, $base, $state, 'zip')) {
                $zip++;
            }

            if ($this->boundaryArtifact($artifacts, 'geometry', $partial, $base, $state, 'geo')) {
                $geo++;
            }

            // 228 MB across fifteen states, against 20 MB for every ZIP index in the
            // register. It buys one rung of the ladder — a house number instead of a
            // ZIP+4 — so it is asked for by name, never assumed.
            if (in_array($state, $streets, true) && $this->streetIndex($artifacts, $partial, $base, $state)) {
                $street++;
            }
        }

        $say(sprintf('  boundaries — %d ZIP, %d geometry, %d street index', $zip, $geo, $street));
    }

    /**
     * Fetch one boundary file, preferring the address the listing gives absolutely.
     *
     * The listing offers `url` and `artifact` for every file and THEY DISAGREE for
     * the ZIP layer: `url` answers 200 while the `artifact` path 404s. Reading
     * `artifact` first — which is the field that reads like a path — silently
     * fetched nothing for every state, and the compile reported "0 ZIP" as if the
     * release shipped none. So `url` leads and `artifact` is the fallback.
     *
     * @param  array<array-key, mixed>  $artifacts
     */
    private function boundaryArtifact(array $artifacts, string $kind, string $partial, string $base, string $state, string $suffix): bool
    {
        $entry = $artifacts[$kind] ?? null;

        if (! is_array($entry)) {
            return false;
        }

        $url = $entry['url'] ?? null;
        $artifact = $entry['artifact'] ?? null;

        $address = match (true) {
            is_string($url) && $url !== '' => $url,
            is_string($artifact) && $artifact !== '' => $base.'/'.$artifact,
            default => null,
        };

        if ($address === null) {
            return false;
        }

        $to = $partial.'/boundaries/'.$state.'.'.$suffix.'.json';

        return $this->fetcher->download($address, $to) !== null;
    }

    /**
     * The street layer, SHARDED BY ZIP rather than written whole.
     *
     * Georgia's street artifact is 38 MB and there are fifteen of them. Reading one
     * as a document costs hundreds of megabytes to answer a question about a single
     * postcode — the same reason the rates are sharded, one rung further down. The
     * sets table is small and shared, so it is written once beside the shard.
     *
     * @param  array<array-key, mixed>  $artifacts
     */
    private function streetIndex(array $artifacts, string $partial, string $base, string $state): bool
    {
        $entry = $artifacts['street'] ?? null;

        if (! is_array($entry)) {
            return false;
        }

        $url = $entry['url'] ?? null;
        $artifact = $entry['artifact'] ?? null;
        $address = match (true) {
            is_string($url) && $url !== '' => $url,
            is_string($artifact) && $artifact !== '' => $base.'/'.$artifact,
            default => null,
        };

        if ($address === null) {
            return false;
        }

        $download = $partial.'/.download/'.$state.'.streets.json';

        if ($this->fetcher->download($address, $download) === null) {
            return false;
        }

        $sets = [];

        foreach (JsonArrayStream::fromFile($download, 'sets') as $set) {
            $sets[] = $set;
        }

        $this->put($partial, 'boundaries/'.$state.'.street.sets.json', [
            'formatVersion' => 3,
            'sets' => $sets,
        ]);

        $writer = new ShardWriter($partial.'/boundaries/'.$state.'.street');

        foreach (JsonArrayStream::membersOfFile($download, 'street') as $zip => $streets) {
            $writer->append((string) $zip, $streets);
        }

        $writer->close();
        @unlink($download);

        return true;
    }

    /**
     * The manifest: what this store is, and a sha256 for every file in it.
     *
     * Written LAST, because the rename that makes the version live happens after it
     * and {@see StoreLayout::installed()} counts a version only when its manifest
     * exists. A store with no manifest is a store nobody will read.
     *
     * @param  array<string, mixed>  $release
     * @param  list<string>  $regions
     * @param  list<string>|null  $states
     * @param  list<string>  $streets
     * @return list<array{path: string, bytes: int, sha256: string}>
     */
    private function manifest(string $partial, string $version, array $release, array $regions, ?array $states, array $streets): array
    {
        @rmdir($partial.'/.download');

        $files = [];

        foreach ($this->walk($partial) as $absolute) {
            $relative = substr($absolute, strlen($partial) + 1);
            $size = filesize($absolute);
            $hash = hash_file('sha256', $absolute);

            $files[] = [
                'path' => $relative,
                'bytes' => $size === false ? 0 : $size,
                'sha256' => $hash === false ? '' : $hash,
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        $this->put($partial, 'manifest.json', [
            'version' => $version,
            'contentHash' => $release['contentHash'] ?? null,
            'schemaVersion' => $release['schemaVersion'] ?? null,
            'publishedAt' => $release['publishedAt'] ?? null,
            'compiledAt' => gmdate('c'),
            'regions' => $regions,
            'states' => $states,
            'streets' => $streets,
            'licence' => [
                'id' => 'PolyForm-Internal-Use-1.0.0',
                'url' => 'https://polyformproject.org/licenses/internal-use/1.0.0/',
                'permits' => 'Any use inside your own organisation, including commercial use.',
                'forbids' => 'Redistribution, resale, or making this data available to anyone outside your organisation.',
            ],
            'files' => $files,
        ]);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function walk(string $directory): array
    {
        $found = [];

        foreach ((array) scandir($directory) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                $found = [...$found, ...$this->walk($path)];

                continue;
            }

            $found[] = $path;
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  list<string>|null  $wanted
     * @return list<string>
     */
    private function regionsOf(array $release, ?array $wanted): array
    {
        $links = $release['links'] ?? null;
        $regions = is_array($links) ? ($links['regions'] ?? null) : null;
        $names = [];

        foreach (is_array($regions) ? $regions : [] as $url) {
            if (is_string($url)) {
                $names[] = basename($url);
            }
        }

        if ($wanted === null) {
            return $names;
        }

        return array_values(array_intersect($names, $wanted));
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function assertSchema(array $release, string $version): void
    {
        $schema = $release['schemaVersion'] ?? null;

        if (! is_string($schema) || ! str_starts_with($schema, self::SCHEMA_MAJOR.'.')) {
            throw DatasetUnreadable::corruptShard(
                $version,
                sprintf('the release declares schemaVersion %s; this package reads %s.x', is_string($schema) ? $schema : 'nothing', self::SCHEMA_MAJOR),
            );
        }
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    private function put(string $partial, string $relative, array $payload): void
    {
        $path = $partial.'/'.$relative;
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Remove a directory and everything under it.
     *
     * Used on the partial before building and on the destination before the rename,
     * because `rename` onto a non-empty directory fails — and a failed rename after
     * a successful compile is the one way to spend the whole download and end up
     * with nothing live.
     */
    private function reset(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach ((array) scandir($directory) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                $this->reset($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
