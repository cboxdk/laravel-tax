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
    // Objects and arrays are both read — the rate sections are the first, a boundary
    // `sets` table the second. A scalar means the document is not what the caller
    // thinks it is, and skipping it would return a section short by however many it
    // happened to contain.
    expect(fn (): array => streamOf('{"rates":["25","10"]}'))->toThrow(DatasetUnreadable::class)
        ->and(fn (): array => streamOf('{"rates":[1, 2]}'))->toThrow(DatasetUnreadable::class);
});

it('reads an array of arrays, which is what a boundary sets table is', function (): void {
    $sets = streamOf('{"sets":[[{"level":"state","code":"20"}],[],[{"level":"city","code":"36000"}]]}', 'sets');

    expect($sets)->toHaveCount(3)
        ->and($sets[0][0]['code'])->toBe('20')
        ->and($sets[1])->toBe([]);
});

it('does not mistake a string VALUE equal to the key for the key itself', function (): void {
    // The scan matched any string equal to the key, then skipped "insignificant"
    // bytes to look for the colon that would confirm it — and commas count as
    // insignificant. So on a VALUE it swallowed the separator and then ate the
    // opening quote of the next key, after which it read that key's body as
    // structure and every match downstream was nonsense. A register document with a
    // `"description": "rates"` anywhere ahead of the real section reported the
    // section missing.
    expect(streamOf('{"description":"rates","rates":[{"percentage":"5"},{"percentage":"7"}]}'))
        ->toHaveCount(2)
        ->and(streamOf('{"note":"rates, mostly","rates":[{"percentage":"9"}]}')[0]['percentage'])
        ->toBe('9');
});

it('still ignores a key of the same name nested inside a record', function (): void {
    // The other half of the same problem: depth 1 is the section, and a `rates` key
    // inside a record is not it. Fixing the value case must not loosen this.
    expect(streamOf('{"meta":{"rates":[{"no":"nested"}]},"rates":[{"yes":"top"}]}'))
        ->toBe([['yes' => 'top']]);
});

it('does not grow its buffer while hunting for a key that is not there', function (): void {
    // A missing key means scanning to the end of the document, and nothing scanned is
    // ever read again — but the bytes were being kept anyway. On the 48.8 MB US
    // region that turned "this release renamed a section" into an out-of-memory
    // FATAL, which is not a diagnosis anybody can act on.
    //
    // Read from a FILE, not `php://memory`: an in-memory stream holds the document
    // itself in the same heap, which would mask exactly what is being measured. The
    // padding is many small records rather than one long string, because that is the
    // shape of the document this actually failed on.
    $path = tempnam(sys_get_temp_dir(), 'jas');
    $record = '{"jurisdiction":"us:KS:CITY-36000","percentage":"1.625"},';
    file_put_contents($path, '{"other":['.str_repeat($record, 60_000).'{"last":true}],"rates":[{"a":1}]}');

    try {
        // PEAK, reset first, and that detail is the whole test. Measuring usage after
        // the scan measures nothing: the exception destroys the reader and frees its
        // buffer on the way out, so a version that retained all 3 MB reports the same
        // zero as one that retained none.
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        expect(fn (): array => iterator_to_array(JsonArrayStream::fromFile($path, 'nosuchkey')))
            ->toThrow(DatasetUnreadable::class);

        // Three megabytes scanned; the buffer holds two 256 KB chunks at most.
        expect(memory_get_peak_usage() - $before)->toBeLessThan(1_500_000);
    } finally {
        @unlink($path);
    }
});

it('reads an empty map published as an empty list, and nothing else as one', function (): void {
    // PHP encodes an empty map as `[]`, and Michigan's postal table is exactly that.
    $path = tempnam(sys_get_temp_dir(), 'jas');

    try {
        file_put_contents($path, '{"zip":[],"sets":[]}');
        expect(iterator_to_array(JsonArrayStream::membersOfFile($path, 'zip')))->toBe([]);

        file_put_contents($path, '{"zip":[["0000","9999",1]]}');
        expect(fn (): array => iterator_to_array(JsonArrayStream::membersOfFile($path, 'zip')))->toThrow(DatasetUnreadable::class);

        // ...and a map still reads, with list values as the postal layer has them.
        file_put_contents($path, '{"zip":{"53001":[["1121","1121",2]]}}');
        expect(iterator_to_array(JsonArrayStream::membersOfFile($path, 'zip')))->toBe(['53001' => [['1121', '1121', 2]]]);
    } finally {
        @unlink($path);
    }
});

it('reads a top-level scalar without decoding the document', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'jas');

    try {
        file_put_contents($path, '{"formatVersion": 3, "state":"WI", "sets":[], "note":"formatVersion"}');
        expect(JsonArrayStream::scalarOfFile($path, 'formatVersion'))->toBe(3)
            ->and(JsonArrayStream::scalarOfFile($path, 'state'))->toBe('WI')
            ->and(fn (): string|int|float|bool|null => JsonArrayStream::scalarOfFile($path, 'sets'))->toThrow(DatasetUnreadable::class)
            ->and(fn (): string|int|float|bool|null => JsonArrayStream::scalarOfFile($path, 'missing'))->toThrow(DatasetUnreadable::class);
    } finally {
        @unlink($path);
    }
});
