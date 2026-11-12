<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Compile;

use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Contracts\TaxRegister;
use Cbox\Tax\Exceptions\DatasetUnreadable;
use Cbox\Tax\Register\Store\ShardWriter;

/**
 * Turns one published boundary artifact, already on local disk, into the files the
 * boundary resolver reads a record at a time.
 *
 * The same step for everybody who holds the artifacts: `tax:data:sync` runs it on
 * what it downloads, and a host whose dataset keeps the register elsewhere runs it
 * on the files it has, into the directory its {@see RegisterData::boundaryPath()}
 * names. One writer of the format, so two stores cannot read differently.
 *
 * `$directory` is the `boundaries` directory of a store; every file is named for its
 * state (`TX.zip`, `TX.zip.head.json`, `KS.street`, `CA.geo`, …).
 *
 * @internal Changes with the register's format. Applications ask the register through
 *           {@see TaxRegister}.
 */
class BoundaryCompiler
{
    /**
     * The postal layer, SHARDED BY ZIP the way the street layer already is.
     *
     * Written whole, it was read whole: every address lookup decoded its state's
     * entire ZIP table to answer a question about one postcode. Wisconsin's is 3.6 MB
     * on disk and cost 107 MB of memory per lookup — more than a default PHP request
     * has — so pricing an address in Wisconsin or Tennessee could take a web request
     * down with it.
     *
     * The resolver reads four things from this artifact: `formatVersion`, `sets`,
     * `ranges` and `zip`. The first three are small and shared by every postcode in
     * the state, so they are written once as a head; `zip` goes into a shard keyed by
     * ZIP5, and a lookup reads the head and one record.
     */
    public function postal(string $artifact, string $directory, string $state): void
    {
        $sets = [];

        foreach (JsonArrayStream::fromFile($artifact, 'sets') as $set) {
            $sets[] = $set;
        }

        $ranges = [];

        foreach (JsonArrayStream::fromFile($artifact, 'ranges') as $range) {
            $ranges[] = $range;
        }

        $this->put($directory.'/'.$state.'.zip.head.json', [
            'formatVersion' => JsonArrayStream::scalarOfFile($artifact, 'formatVersion'),
            'sets' => $sets,
            'ranges' => $ranges,
        ]);

        $writer = new ShardWriter($directory.'/'.$state.'.zip');

        foreach (JsonArrayStream::membersOfFile($artifact, 'zip') as $zip5 => $rows) {
            $writer->append((string) $zip5, $rows);
        }

        $writer->close();
    }

    /**
     * The street layer, SHARDED BY ZIP rather than written whole.
     *
     * Georgia's street artifact is 38 MB and there are fifteen of them. Reading one
     * as a document costs hundreds of megabytes to answer a question about a single
     * postcode — the same reason the rates are sharded, one rung further down. The
     * sets table is small and shared, so it is written once beside the shard.
     */
    public function street(string $artifact, string $directory, string $state): void
    {
        $sets = [];

        foreach (JsonArrayStream::fromFile($artifact, 'sets') as $set) {
            $sets[] = $set;
        }

        $this->put($directory.'/'.$state.'.street.sets.json', [
            'formatVersion' => 3,
            'sets' => $sets,
        ]);

        $writer = new ShardWriter($directory.'/'.$state.'.street');

        foreach (JsonArrayStream::membersOfFile($artifact, 'street') as $zip => $streets) {
            $writer->append((string) $zip, $streets);
        }

        $writer->close();
    }

    /**
     * The polygon layer's per-feature shard and bounding boxes, from
     * `<directory>/<STATE>.geo.json`, which stays where it is.
     */
    public function geometry(string $directory, string $state): void
    {
        GeometryIndex::write($directory, $state);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function put(string $path, array $payload): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || file_put_contents($path, $json) === false) {
            throw DatasetUnreadable::cannotInstall($path, 'the boundary file could not be written');
        }
    }
}
