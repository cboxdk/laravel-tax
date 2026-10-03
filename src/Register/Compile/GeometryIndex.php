<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Compile;

use Cbox\Tax\Contracts\TaxRegister;
use Cbox\Tax\Exceptions\DatasetUnreadable;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\Register\Store\ShardWriter;

/**
 * A state's polygon layer, SHARDED BY FEATURE with an index of bounding boxes.
 *
 * Read whole, it was read whole: every Texas lookup decoded 8.4 MB of GeoJSON — some
 * 236 MB as PHP arrays — to test one point, past a default 128 MB memory limit. Walked
 * here one feature at a time, each is written to a shard under its position and its
 * bounding box to a small index; a lookup reads the index, keeps the features whose box
 * holds the point, and decodes only those. The original file stays beside it, for a
 * reader that predates the index.
 *
 * @internal Changes with the register's format. Applications ask the register through
 *           {@see TaxRegister}.
 */
class GeometryIndex
{
    /** Index `<dir>/<STATE>.geo.json` into `<STATE>.geo` (+ `.idx`) and `<STATE>.geo.boxes.json`. */
    public static function write(string $directory, string $state): void
    {
        $path = $directory.'/'.$state.'.geo.json';
        $writer = new ShardWriter($directory.'/'.$state.'.geo');
        $boxes = [];

        foreach (JsonArrayStream::fromFile($path, 'features') as $position => $feature) {
            $box = self::boundingBox(Shape::map($feature['geometry'] ?? null)['coordinates'] ?? null);

            if ($box === null) {
                continue;
            }

            $key = 'f'.$position;
            $writer->append($key, $feature);
            $boxes[] = [...$box, $key];
        }

        $writer->close();

        $json = json_encode([
            'formatVersion' => JsonArrayStream::scalarOfFile($path, 'formatVersion'),
            'boxes' => $boxes,
        ], JSON_UNESCAPED_SLASHES);

        if ($json === false || file_put_contents($directory.'/'.$state.'.geo.boxes.json', $json) === false) {
            throw DatasetUnreadable::cannotOpen($directory.'/'.$state.'.geo.boxes.json');
        }
    }

    /**
     * The smallest box around every coordinate pair in a (multi)polygon, as
     * [minLng, minLat, maxLng, maxLat]; null where there is none.
     *
     * @return array{float, float, float, float}|null
     */
    private static function boundingBox(mixed $coordinates): ?array
    {
        $box = null;
        $stack = [$coordinates];

        while ($stack !== []) {
            $node = array_pop($stack);

            if (! is_array($node)) {
                continue;
            }

            if (count($node) >= 2 && is_numeric($node[0] ?? null) && is_numeric($node[1] ?? null)) {
                $lng = (float) $node[0];
                $lat = (float) $node[1];
                $box = $box === null
                    ? [$lng, $lat, $lng, $lat]
                    : [min($box[0], $lng), min($box[1], $lat), max($box[2], $lng), max($box[3], $lat)];

                continue;
            }

            foreach ($node as $child) {
                $stack[] = $child;
            }
        }

        return $box;
    }
}
