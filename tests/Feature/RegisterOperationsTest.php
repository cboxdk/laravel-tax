<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Exceptions\DatasetNotInstalled;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Support\Facades\Http;

/** Small published documents exercise the real compiler and command wiring offline. */
function publishOperationRegister(array $versions = ['2026.09.17-100']): void
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

    Http::fake($responses);
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
