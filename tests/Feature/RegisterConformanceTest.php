<?php

declare(strict_types=1);

use Cbox\Tax\Register\Compile\Compiler;
use Cbox\Tax\Register\Compile\SectionFetcher;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterBoundaries;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cboxdk\TaxResolver\Authority;
use Cboxdk\TaxResolver\ParsedAddress;
use Illuminate\Filesystem\Filesystem;

/*
 * The register's own conformance deck, run against this engine's resolution.
 *
 * This checks address-to-authority resolution against published artifacts. Rate
 * calculation has separate tests; this deck checks the authority set, not a total.
 * The register cuts these cases out of the artifacts a release
 * actually ships, and reads them back through the same shared resolver this engine
 * uses. Two readers of one format drift apart quietly — that is exactly what happened
 * when the resolver package read a formatVersion 3 artifact as a v2 one and answered
 * "no local authority levies here" for every address in twenty-four states.
 *
 * It is not SST's certification deck, and passing it is not certification. Failing it
 * means this engine and the register disagree about a file they both hold.
 */

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/cbox-tax-deck-'.getmypid().'-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->root);
});

it('resolves the register\'s own conformance deck the way the register does', function (): void {
    $fetcher = app(SectionFetcher::class);
    $version = $fetcher->resolve('latest');
    $deck = $fetcher->json("/api/v1/releases/{$version}/conformance");

    // TWO STATES, not the fifteen the deck covers. Every rung of the ladder is
    // exercised by these — Kansas has the narrow-span-beats-whole-ZIP case and an
    // empty authority set, Arkansas has street ranges with both parities — and
    // compiling the rest would pull 228 MB of street indexes to re-prove the same
    // three rules.
    $states = ['KS', 'AR'];

    $cases = array_values(array_filter(
        is_array($deck['cases'] ?? null) ? $deck['cases'] : [],
        static fn (mixed $case): bool => is_array($case)
            && is_string($case['state'] ?? null)
            && in_array($case['state'], ['KS', 'AR'], true),
    ));

    expect($cases)->not->toBe([]);
    $layout = new StoreLayout($this->root);

    $result = new Compiler($fetcher, $layout)->compile(
        $version, ['us'], $states, true, $states, fn (string $line): null => null,
    );
    new StorePointer($layout)->pointAt($result['version']);

    $dataset = new RegisterDataset($layout, new StorePointer($layout));
    $boundaries = new RegisterBoundaries($layout, $result['version'], $dataset);

    $checked = 0;
    $disagreed = [];

    foreach ($cases as $case) {
        $address = $case['address'] ?? null;
        $expect = $case['expect'] ?? null;

        if (! is_array($address) || ! is_array($expect)) {
            continue;
        }

        $resolved = $boundaries->resolveParsed((string) $case['state'], new ParsedAddress(
            zip5: (string) ($address['zip5'] ?? ''),
            plus4: (string) ($address['plus4'] ?? ''),
            houseNumber: is_int($address['houseNumber'] ?? null) ? $address['houseNumber'] : null,
            preDir: (string) ($address['preDir'] ?? ''),
            street: (string) ($address['street'] ?? ''),
            suffix: (string) ($address['suffix'] ?? ''),
            postDir: (string) ($address['postDir'] ?? ''),
        ));

        $wanted = array_map(
            static fn (array $a): string => $a['level'].':'.$a['code'],
            array_values(array_filter(
                is_array($expect['authorities'] ?? null) ? $expect['authorities'] : [],
                is_array(...),
            )),
        );

        $got = array_map(
            static fn (Authority $a): string => $a->key(),
            $resolved ?? [],
        );

        $checked++;

        if ($got !== $wanted) {
            $disagreed[] = sprintf('%s: expected [%s], got [%s]', $case['id'] ?? '?', implode(' ', $wanted), implode(' ', $got));
        }
    }

    expect($checked)->toBeGreaterThan(10)
        ->and($disagreed)->toBe([]);
})->group('e2e');
