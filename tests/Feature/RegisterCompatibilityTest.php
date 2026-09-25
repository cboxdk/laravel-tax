<?php

declare(strict_types=1);

use Cbox\Tax\Exceptions\DatasetUnreadable;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Register\Compile\Compiler;
use Cbox\Tax\Register\Compile\SectionFetcher;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('rejects an unreviewed schema before downloading sections or replacing the active store', function (?string $schema): void {
    $version = '2026.09.18-999';
    $active = app(StorePointer::class)->current();
    Http::fake(['https://data.cboxtax.com/api/v1/releases/'.$version => Http::response([
        'version' => $version, 'schemaVersion' => $schema,
    ])]);

    expect(fn () => app(Compiler::class)->compile($version, null, null, false, [], static fn (): null => null))
        ->toThrow(DatasetUnreadable::class, 'unsupported schemaVersion');
    expect(app(StorePointer::class)->current())->toBe($active)
        ->and(is_dir(app(StoreLayout::class)->version($version)))->toBeFalse();
    Http::assertSentCount(1);
})->with(['3.0.0', '2.7.0', '1.35.0', '1.34.invalid', null]);

it('also rejects an incompatible store installed by another worker before offline reads', function (?string $schema): void {
    $layout = app(StoreLayout::class);
    $version = app(StorePointer::class)->current();
    $manifest = json_decode(file_get_contents($layout->manifest($version)), true, flags: JSON_THROW_ON_ERROR);
    $manifest['schemaVersion'] = $schema;
    file_put_contents($layout->manifest($version), json_encode($manifest, JSON_THROW_ON_ERROR));
    // Explicit pins must not bypass compatibility checks either.
    $dataset = new RegisterDataset($layout, app(StorePointer::class), $version);

    expect(fn (): array => $dataset->ratesFor('eu:DK'))->toThrow(DatasetUnreadable::class, 'unsupported schemaVersion')
        ->and(fn (): array => $dataset->rulesFor('us:NY'))->toThrow(DatasetUnreadable::class, 'unsupported schemaVersion');
    Http::assertNothingSent();
})->with(['3.0.0', '2.7.0', '1.35.0', '1.34.invalid', null]);

it('rejects additional rule conditions during compilation even if labelled with the old schema', function (): void {
    $version = '2026.09.18-999';
    $base = 'https://data.cboxtax.com/api/v1/releases/'.$version;
    $active = app(StorePointer::class)->current();
    Http::fake([
        $base => Http::response(['version' => $version, 'schemaVersion' => '1.34.0']),
        $base.'/sections/rules' => Http::response(['rules' => [[
            'jurisdiction' => 'us:KS', 'kind' => 'taxable_base',
            'effective' => ['from' => '2026-01-01', 'until' => null],
            // Illustrative future syntax, deliberately not part of schema 1.34.
            'payload' => ['component' => 'transport_on_taxable_goods', 'included' => true, 'conditions' => ['unknown_predicate' => true]],
        ]]]),
        $base.'/sections/*' => Http::response([]),
    ]);

    expect(fn () => app(Compiler::class)->compile($version, null, null, false, [], static fn (): null => null))
        ->toThrow(UnresolvedTaxRule::class, 'payload.conditions');
    expect(app(StorePointer::class)->current())->toBe($active)
        ->and(is_file(app(StoreLayout::class)->manifest($version)))->toBeFalse();
});

it('rejects unknown applicability fields on the rule envelope during offline reads', function (): void {
    $path = app(StoreLayout::class)->file(app(StorePointer::class)->current(), 'rules.json');
    $document = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $document['rules'][] = [
        'jurisdiction' => 'us:KS', 'kind' => 'sourcing',
        'payload' => ['basis' => 'destination'],
        'effective' => ['from' => '2026-01-01', 'until' => null],
        'conditions' => ['unknown_predicate' => true],
    ];
    file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

    expect(fn () => app(RegisterDataset::class)->rulesOn('us:KS', 'sourcing', new DateTimeImmutable('2026-09-18')))
        ->toThrow(UnresolvedTaxRule::class, 'conditions');
    Http::assertNothingSent();
});

function releasesAhead(string $readable, string $ahead = '2026.12.01-900', string $aheadSchema = '3.0.0'): void
{
    Http::fake([
        'https://data.cboxtax.com/api/v1/releases/latest' => Http::response(['version' => $ahead, 'schemaVersion' => $aheadSchema, 'publishedAt' => '2026-12-01T10:00:00+00:00']),
        'https://data.cboxtax.com/api/v1/releases' => Http::response(['releases' => [
            ['version' => $readable, 'schemaVersion' => '2.6.1', 'publishedAt' => '2026-11-30T10:00:00+00:00'],
            ['version' => $ahead, 'schemaVersion' => $aheadSchema, 'publishedAt' => '2026-12-01T10:00:00+00:00'],
            ['version' => '2026.01.01-1', 'schemaVersion' => '2.0.0', 'publishedAt' => '2026-01-01T10:00:00+00:00'],
        ]]),
    ]);
}

it('takes the newest release it can read when latest is on a schema it cannot', function (): void {
    // The register moves ahead of the package. Refusing `latest` outright froze the
    // installed data until somebody upgraded, and every correction published on the
    // old schema in the meantime was left behind.
    releasesAhead('2026.11.30-899');

    expect(app(SectionFetcher::class)->newestReadable())->toBe([
        'version' => '2026.11.30-899',
        'latest' => '2026.12.01-900',
        'latestSchema' => '3.0.0',
    ]);
});

it('refuses when nothing published is readable', function (): void {
    Http::fake([
        'https://data.cboxtax.com/api/v1/releases/latest' => Http::response(['version' => '2026.12.01-900', 'schemaVersion' => '3.0.0']),
        'https://data.cboxtax.com/api/v1/releases' => Http::response(['releases' => [
            ['version' => '2026.12.01-900', 'schemaVersion' => '3.0.0', 'publishedAt' => '2026-12-01T10:00:00+00:00'],
        ]]),
    ]);

    expect(fn () => app(SectionFetcher::class)->newestReadable())
        ->toThrow(DatasetUnreadable::class, 'unsupported schemaVersion 3.0.0');
});

it('checks against the newest readable release and says what it skipped', function (): void {
    $installed = app(RegisterDataset::class)->version();
    releasesAhead((string) $installed);

    $this->artisan('tax:data:sync', ['--check' => true])
        ->expectsOutputToContain('cannot read')
        ->expectsOutputToContain('Up to date')
        ->assertSuccessful();
});

it('still refuses a release asked for by name on a schema it cannot read', function (): void {
    Http::fake(['https://data.cboxtax.com/api/v1/releases/2026.12.01-900' => Http::response([
        'version' => '2026.12.01-900', 'schemaVersion' => '3.0.0',
    ])]);

    expect(fn () => app(Compiler::class)->compile('2026.12.01-900', null, null, false, [], static fn (): null => null))
        ->toThrow(DatasetUnreadable::class, 'unsupported schemaVersion');
});
