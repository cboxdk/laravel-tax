<?php

declare(strict_types=1);

use Cbox\Tax\Exceptions\DatasetUnreadable;
use Cbox\Tax\Register\Compile\JsonArrayStream;

/*
 * The reader that makes the register readable at all: the US region is 48.8 MB and
 * `json_decode` on it peaks at 315 MB, which blows the DEFAULT memory limit. These
 * cases are the ones that decide whether a scan can be trusted to find structure.
 */

function streamOf(string $json, string $key = 'rates'): array
{
    $handle = fopen('php://memory', 'r+b');
    fwrite($handle, $json);
    rewind($handle);

    try {
        return iterator_to_array(new JsonArrayStream($handle, chunk: 8)->objects($key), false);
    } finally {
        fclose($handle);
    }
}

it('is not fooled by braces inside a quoted string', function (): void {
    // The register quotes statutes at length, in twenty-three languages. A brace in a
    // provenance note counted as structure ends the record early.
    $records = streamOf('{"rates":[{"note":"Annex 1 {see § 10} and [Anlage 3]","pct":"4.9"},{"pct":"10"}]}');

    expect($records)->toHaveCount(2)
        ->and($records[0]['note'])->toBe('Annex 1 {see § 10} and [Anlage 3]')
        ->and($records[1]['pct'])->toBe('10');
});

it('is not fooled by an escaped quote, or by an escaped backslash before one', function (): void {
    $records = streamOf('{"rates":[{"a":"he said \"25%\" once"},{"b":"ends with a backslash \\\\"},{"c":"fine"}]}');

    expect($records)->toHaveCount(3)
        ->and($records[0]['a'])->toBe('he said "25%" once')
        ->and($records[1]['b'])->toBe('ends with a backslash \\')
        ->and($records[2]['c'])->toBe('fine');
});

it('takes the array at the top level, not one nested inside a record', function (): void {
    // A record carrying its own `rates` key must not start the read mid-document.
    $records = streamOf('{"meta":{"rates":[{"decoy":true}]},"rates":[{"real":1},{"real":2}]}');

    expect($records)->toHaveCount(2)
        ->and($records[0]['real'])->toBe(1);
});

it('reads records larger than the chunk it reads in', function (): void {
    $long = str_repeat('x', 5000);
    $records = streamOf('{"rates":[{"note":"'.$long.'"}]}');

    expect($records)->toHaveCount(1)
        ->and($records[0]['note'])->toHaveLength(5000);
});

it('handles an empty array and a document with no such section', function (): void {
    expect(streamOf('{"rates":[]}'))->toBe([])
        ->and(fn (): array => streamOf('{"other":[]}'))->toThrow(DatasetUnreadable::class);
});

it('refuses a truncated document instead of returning what it managed to read', function (): void {
    // Half a download is not a small section. Returning the records that happened to
    // land is how a shard ends up short and nothing says so.
    expect(fn (): array => streamOf('{"rates":[{"a":1},{"b":'))
        ->toThrow(DatasetUnreadable::class);
});

it('refuses an array of scalars, which is not the shape it knows', function (): void {
    expect(fn (): array => streamOf('{"rates":["25","10"]}'))
        ->toThrow(DatasetUnreadable::class);
});
