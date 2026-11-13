<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Exceptions\DatasetNotInstalled;
use Cbox\Tax\Exceptions\RateSourceUnavailable;
use Cbox\Tax\Register\Compile\SectionFetcher;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Support\Facades\Http;

/** Small published documents exercise the real compiler and command wiring offline. */
function publishOperationRegister(array $versions = ['2026.09.17-100'], array $overrides = []): void
{
    $responses = [
        'https://data.cboxtax.com/api/v1/releases/latest' => Http::response(['version' => end($versions)]),
    ];

    foreach ($versions as $version) {
        $base = 'https://data.cboxtax.com/api/v1/releases/'.$version;
        $responses[$base] = Http::response([
            'version' => $version,
            'schemaVersion' => '1.34.0',
            'links' => ['regions' => [$base.'/regions/eu', $base.'/regions/us']],
        ]);

        foreach (['regimes', 'categories', 'mappings', 'sources', 'rules', 'standard-rates'] as $section) {
            $responses[$base.'/sections/'.$section] = Http::response([]);
        }

        $responses[$base.'/coverage'] = Http::response([]);
        $responses[$base.'/boundaries'] = Http::response(['states' => []]);

        foreach (['eu' => ['eu:DK'], 'us' => ['us:TX', 'us:KS']] as $region => $codes) {
            $responses[$base.'/regions/'.$region] = Http::response([
                'rates' => array_map(static fn (string $code): array => ['jurisdiction' => $code, 'percentage' => '25'], $codes),
                'jurisdictions' => array_map(static fn (string $code): array => ['code' => $code, 'level' => 'country'], $codes),
            ]);
        }
    }

    Http::fake([...$responses, ...$overrides]);
}

function operationManifest(string $version = '2026.09.17-100'): array
{
    return json_decode(file_get_contents(app(StoreLayout::class)->manifest($version)), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('prices from the configured pin even when another release is active', function (): void {
    $root = app(StoreLayout::class)->root();
    FakeRegister::at($root, '2026.09.17-99')->rate('eu:DK', '20')->install();
    FakeRegister::at($root, '2026.09.17-100')->rate('eu:DK', '25')->install();
    config()->set('tax.register.version', '2026.09.17-99');

    $assessment = app(TaxCalculator::class)->assess(new TaxQuery(
        Money::of('100', 'DKK'),
        Pricing::Exclusive,
        app(JurisdictionRepository::class)->find(new CountryCode('DK')),
        CustomerType::Consumer,
        new SellerRegistrations(new CountryCode('DK')),
    ));

    expect((string) $assessment->tax->getAmount())->toBe('20.00')
        ->and($assessment->rate->provenance->version)->toBe('2026.09.17-99')
        ->and(app(StorePointer::class)->current())->toBe('2026.09.17-100');
});

it('refuses a missing pin instead of using the active release', function (): void {
    config()->set('tax.register.version', '2026.09.17-999');

    expect(app(RegisterDataset::class)->isInstalled())->toBeFalse()
        ->and(fn () => app(RegisterDataset::class)->requireVersion())
        ->toThrow(DatasetNotInstalled::class, 'Pinned tax register 2026.09.17-999');

    $this->artisan('tax:data:status', ['--offline' => true])
        ->expectsOutputToContain('Pinned register 2026.09.17-999 is not installed')
        ->assertFailed();
});

it('honours sync configuration and verifies the resulting store offline', function (): void {
    publishOperationRegister();
    config()->set('tax.register.version', '2026.09.17-100');
    config()->set('tax.register.regions', ['US']);
    config()->set('tax.register.states', ' tx, TX ');
    config()->set('tax.register.streets', 'tx');
    config()->set('tax.register.boundaries', false);
    config()->set('tax.register.keep', 1);

    $this->artisan('tax:data:sync')->assertSuccessful();

    $manifest = operationManifest();
    expect($manifest['regions'])->toBe(['us'])
        ->and($manifest['states'])->toBe(['TX'])
        ->and($manifest['streets'])->toBe(['TX'])
        ->and(app(StoreLayout::class)->installed())->toBe(['2026.09.17-100'])
        ->and(app(RegisterDataset::class)->ratesFor('us:TX'))->toHaveCount(1)
        ->and(app(RegisterDataset::class)->ratesFor('us:KS'))->toBe([]);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/latest') || str_contains($request->url(), '/boundaries'));
    $requests = count(Http::recorded());

    $this->artisan('tax:data:verify')->expectsOutputToContain('Verified 2026.09.17-100:')->assertSuccessful();
    $this->artisan('tax:data:status', ['--offline' => true])->expectsOutputToContain('Pricing version')->assertSuccessful();
    Http::assertSentCount($requests);
});

it('lets CLI options override sync defaults without moving the pricing pin', function (): void {
    publishOperationRegister(['2026.09.17-99', '2026.09.17-100']);
    FakeRegister::at(app(StoreLayout::class)->root(), '2026.09.17-99')->rate('eu:DK', '20')->install();
    config()->set('tax.register.version', '2026.09.17-99');
    config()->set('tax.register.regions', 'us');
    config()->set('tax.register.states', ['TX']);
    config()->set('tax.register.streets', ['TX']);
    config()->set('tax.register.keep', 1);

    $this->artisan('tax:data:sync', [
        '--release' => '2026.09.17-100', '--region' => ['eu'], '--state' => ['ks'], '--streets' => ['ks'], '--no-boundaries' => true, '--keep' => '3',
    ])->expectsOutputToContain('Pricing remains pinned to 2026.09.17-99')->assertSuccessful();

    $manifest = operationManifest();
    expect($manifest['regions'])->toBe(['eu'])
        ->and($manifest['states'])->toBe(['KS'])
        ->and($manifest['streets'])->toBe(['KS'])
        ->and(app(StoreLayout::class)->installed())->toHaveCount(3)
        ->and(app(RegisterDataset::class)->version())->toBe('2026.09.17-99');
});

it('does not fetch US boundaries when only a non-US region is selected', function (): void {
    publishOperationRegister();
    config()->set('tax.register.regions', 'eu');

    $this->artisan('tax:data:sync')->assertSuccessful();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/boundaries') || str_contains($request->url(), '/regions/us'));
    expect(operationManifest()['regions'])->toBe(['eu']);
});

it('checks the configured release instead of treating a deliberate pin as behind', function (): void {
    config()->set('tax.register.version', app(StorePointer::class)->current());

    $this->artisan('tax:data:sync', ['--check' => true])->expectsOutputToContain('Up to date:')->assertSuccessful();
});

it('reports an update when the active release differs from latest', function (): void {
    publishOperationRegister();

    $this->artisan('tax:data:sync', ['--check' => true])->expectsOutputToContain('Update needed:')->assertFailed();
});

it('rolls back to an older release using numeric release counters', function (): void {
    $root = app(StoreLayout::class)->root();
    foreach (['2026.09.17-99', '2026.09.17-100', '2026.09.17-101'] as $version) {
        FakeRegister::at($root, $version)->rate('eu:DK', '25')->install();
    }
    app(StorePointer::class)->pointAt('2026.09.17-100');

    $this->artisan('tax:data:activate', ['version' => 'previous'])->assertSuccessful();
    expect(app(StorePointer::class)->current())->toBe('2026.09.17-99');
});

it('never prunes the active release or configured pin', function (): void {
    $root = app(StoreLayout::class)->root();
    foreach (['2026.09.17-98', '2026.09.17-99', '2026.09.17-100', '2026.09.17-101'] as $version) {
        FakeRegister::at($root, $version)->rate('eu:DK', '25')->install();
    }
    config()->set('tax.register.version', '2026.09.17-98');
    config()->set('tax.register.keep', 1);
    app(StorePointer::class)->pointAt('2026.09.17-99');

    $this->artisan('tax:data:prune')->assertSuccessful();
    expect(app(StoreLayout::class)->installed())->toBe(['2026.09.17-98', '2026.09.17-99', '2026.09.17-101']);
});

it('detects modified and missing compiled files', function (string $damage): void {
    publishOperationRegister();
    $this->artisan('tax:data:sync', ['--no-boundaries' => true])->assertSuccessful();
    $path = app(StoreLayout::class)->file('2026.09.17-100', 'coverage.json');

    if ($damage === 'missing') {
        unlink($path);
    } else {
        // Same length as the original JSON: a size check alone cannot detect this.
        file_put_contents($path, '{}');
    }

    $this->artisan('tax:data:verify')->expectsOutputToContain('coverage.json')->assertFailed();
})->with(['modified', 'missing']);

it('rejects an unusable manifest', function (string $damage): void {
    publishOperationRegister();
    $this->artisan('tax:data:sync', ['--no-boundaries' => true])->assertSuccessful();
    $path = app(StoreLayout::class)->manifest('2026.09.17-100');
    $manifest = operationManifest();

    match ($damage) {
        'wrong version' => $manifest['version'] = '2026.09.17-99',
        'empty files' => $manifest['files'] = [],
        'invalid entry' => $manifest['files'][0] = 'broken',
        'escaping path' => $manifest['files'][0]['path'] = '../outside.json',
        'null byte' => $manifest['files'][0]['path'] = "bad\0path",
        'bad digest' => $manifest['files'][0]['sha256'] = 'not a hash',
        'duplicate entry' => $manifest['files'][] = $manifest['files'][0],
        default => null,
    };
    file_put_contents($path, $damage === 'invalid json' ? '{' : json_encode($manifest));

    $this->artisan('tax:data:verify')->assertFailed();
})->with(['wrong version', 'empty files', 'invalid entry', 'escaping path', 'null byte', 'bad digest', 'duplicate entry', 'invalid json']);

it('verifies the pricing pin by default and an explicit release on request', function (): void {
    publishOperationRegister(['2026.09.17-99', '2026.09.17-100']);
    $this->artisan('tax:data:sync', ['--release' => '2026.09.17-99', '--no-boundaries' => true])->assertSuccessful();
    $this->artisan('tax:data:sync', ['--release' => '2026.09.17-100', '--no-boundaries' => true])->assertSuccessful();
    config()->set('tax.register.version', '2026.09.17-99');
    app()->forgetInstance(RegisterDataset::class);

    $this->artisan('tax:data:verify')->expectsOutputToContain('Verified 2026.09.17-99:')->assertSuccessful();
    $this->artisan('tax:data:verify', ['release' => '2026.09.17-100'])->expectsOutputToContain('Verified 2026.09.17-100:')->assertSuccessful();
    $this->artisan('tax:data:verify', ['release' => '2026.09.17-999'])->assertFailed();
});

it('takes a boundary file over one redirect, checked against the hash the listing publishes', function (bool $intact): void {
    $version = '2026.09.17-100';
    $base = 'https://data.cboxtax.com/api/v1/releases/'.$version;
    $overlay = '{"type":"FeatureCollection","features":[]}';
    $storage = 'https://register.fixture-account.r2.cloudflarestorage.com';

    publishOperationRegister(overrides: [
        $base.'/boundaries' => Http::response([
            'integrity' => 'sha256',
            'states' => ['NE' => ['overlay' => [
                'url' => $base.'/boundaries/ne/overlay',
                'artifact' => 'boundaries/ne.overlay.json',
                'bytes' => strlen($overlay),
                'hash' => hash('sha256', $intact ? $overlay : '{}'),
            ]]],
        ]),
        $base.'/boundaries/ne/overlay' => Http::response('', 302, ['Location' => $storage.'/ne.overlay.json?X-Amz-Signature=fixture']),
        $storage.'/*' => Http::response($overlay),
    ]);
    config()->set('tax.register.redirect_origins', $storage);
    config()->set('tax.register.regions', 'us');
    app()->forgetInstance(SectionFetcher::class);

    $sync = fn () => $this->artisan('tax:data:sync')->assertSuccessful()->run();

    if ($intact) {
        $sync();
    } else {
        expect($sync)->toThrow(RateSourceUnavailable::class, 'integrity check failed for '.$base.'/boundaries/ne/overlay');
    }

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), $storage.'/') && ! $request->hasHeader('Authorization'));
    $intact
        ? expect(app(StoreLayout::class)->installed())->toContain($version)
        : expect(app(StoreLayout::class)->installed())->not->toContain($version);
})->with(['intact' => true, 'altered' => false]);

/**
 * A register whose sections and regions are all handed to object storage, the way the
 * register's new home serves them, with the release publishing `sha256` (schema 2.7.9+)
 * or not (older releases). `coverage` and `facts` stay with the register.
 *
 * @return array{0: string, 1: string}
 */
function publishStoredRegister(bool $hashes, ?string $tamper = null, bool $redirectCoverage = false): array
{
    $version = '2026.11.13-1';
    $base = 'https://data.cboxtax.com/api/v1/releases/'.$version;
    $storage = 'https://register.fixture-account.r2.cloudflarestorage.com';
    $documents = [];

    foreach (['regimes', 'categories', 'mappings', 'sources', 'rules', 'standard-rates'] as $section) {
        $documents['sections/'.$section] = '{}';
    }

    $documents['regions/eu'] = json_encode([
        'rates' => [['jurisdiction' => 'eu:DK', 'percentage' => '25']],
        'jurisdictions' => [['code' => 'eu:DK', 'level' => 'country']],
    ], JSON_THROW_ON_ERROR);

    $responses = [];
    $sha256 = ['regions' => [], 'sections' => []];

    foreach ($documents as $address => $body) {
        [$kind, $name] = explode('/', $address);
        $sha256[$kind][$name] = hash('sha256', $body);
        $responses[$base.'/'.$address] = Http::response('', 302, ['Location' => $storage.'/'.$version.'/'.$address.'.json?X-Amz-Signature=fixture']);
        $responses[$storage.'/'.$version.'/'.$address.'.json*'] = Http::response($address === $tamper ? '{"tampered":true}' : $body);
    }

    $responses[$base] = Http::response([
        'version' => $version,
        'schemaVersion' => $hashes ? '2.7.9' : '2.7.8',
        'links' => ['regions' => [$base.'/regions/eu']],
        ...($hashes ? ['sha256' => ['dataset' => str_repeat('0', 64), ...$sha256]] : []),
    ]);
    $responses[$base.'/coverage'] = $redirectCoverage
        ? Http::response('', 302, ['Location' => $storage.'/'.$version.'/coverage.json'])
        : Http::response([]);
    $responses[$base.'/facts'] = Http::response('', 302, ['Location' => $storage.'/'.$version.'/facts.json']);
    $responses[$storage.'/*'] = Http::response('{}');

    Http::fake($responses);
    config()->set('tax.register.redirect_origins', $storage);
    config()->set('tax.register.regions', 'eu');
    app()->forgetInstance(SectionFetcher::class);

    return [$version, $storage];
}

it('compiles a release whose sections and regions come from storage, each checked against its published hash', function (): void {
    [$version, $storage] = publishStoredRegister(hashes: true);

    $this->artisan('tax:data:sync', ['--release' => $version])->assertSuccessful()->run();

    expect(app(StoreLayout::class)->installed())->toContain($version)
        ->and(app(RegisterDataset::class)->ratesFor('eu:DK'))->toHaveCount(1);
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), $storage.'/'.$version.'/regions/eu.json'));
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), $storage.'/'.$version.'/sections/rules.json'));
    Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), $storage.'/') && $request->hasHeader('Authorization'));
    // The fact vocabulary has no hash: its redirect is refused, and the sync carries on without it.
    Http::assertNotSent(fn ($request): bool => $request->url() === $storage.'/'.$version.'/facts.json');
});

it('refuses a release when a stored section or region does not match its published hash, and installs nothing', function (string $tamper): void {
    [$version] = publishStoredRegister(hashes: true, tamper: $tamper);

    expect(fn () => $this->artisan('tax:data:sync', ['--release' => $version])->run())
        ->toThrow(RateSourceUnavailable::class, 'integrity check failed for /api/v1/releases/'.$version.'/'.$tamper);

    expect(app(StoreLayout::class)->installed())->not->toContain($version)
        ->and(is_dir(app(StoreLayout::class)->partial($version).'/.download') ? glob(app(StoreLayout::class)->partial($version).'/.download/*') : [])->toBe([]);
})->with(['sections/rules', 'regions/eu']);

it('refuses a redirect for a release published before hashes, even to an allowed origin', function (): void {
    [$version, $storage] = publishStoredRegister(hashes: false);

    expect(fn () => $this->artisan('tax:data:sync', ['--release' => $version])->run())
        ->toThrow(RateSourceUnavailable::class, 'only a document with a published sha256 may be redirected');

    Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), $storage.'/'));
});

it('never follows a redirect for coverage, which the release publishes no hash for', function (): void {
    [$version, $storage] = publishStoredRegister(hashes: true, redirectCoverage: true);

    expect(fn () => $this->artisan('tax:data:sync', ['--release' => $version])->run())
        ->toThrow(RateSourceUnavailable::class, 'only a document with a published sha256 may be redirected');

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'coverage.json'));
});
