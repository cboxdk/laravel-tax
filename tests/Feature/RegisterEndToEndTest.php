<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Exceptions\DatasetNotInstalled;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Support\Facades\Http;

/*
 * The whole path, as an application walks it: an empty store refuses and says what to
 * run; `tax:data:sync` compiles a real published release; the same assessment then
 * prices from local files with no network call.
 *
 * The e2e group talks to data.cboxtax.com and runs as part of the full QA gate.
 * Use `pest --exclude-group=e2e` when working offline; the other tests use fixtures.
 */

beforeEach(function (): void {
    $this->store = sys_get_temp_dir().'/cbox-tax-e2e-'.getmypid();
    config()->set('tax.register.store', $this->store);
});

afterEach(function (): void {
    $remove = function (string $directory) use (&$remove): void {
        foreach (is_dir($directory) ? (array) scandir($directory) : [] as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $remove($path) : @unlink($path);
        }

        @rmdir($directory);
    };

    $remove($this->store);
});

it('refuses to price anything before a register is synced, and names the command', function (): void {
    $dataset = new RegisterDataset(new StoreLayout($this->store), app(StorePointer::class));

    expect($dataset->isInstalled())->toBeFalse();

    try {
        $dataset->requireVersion();
        $this->fail('an empty store should refuse');
    } catch (DatasetNotInstalled $e) {
        // The refusal has to be actionable. A message that says "no data" and stops
        // sends somebody reading source code.
        expect($e->getMessage())->toContain('tax:data:sync')
            ->and($e->reason()->value)->toBe('rate_unavailable');
    }
});

it('syncs a real release and prices from it, with no network call while pricing', function (): void {
    $this->artisan('tax:data:sync', ['--region' => ['eu'], '--no-boundaries' => true])
        ->assertSuccessful();

    $dataset = app(RegisterDataset::class);
    expect($dataset->isInstalled())->toBeTrue();

    $version = $dataset->version();
    expect($version)->toMatch('/^\d{4}\.\d{2}\.\d{2}-\d+$/');

    // Nothing may reach the network from here on: the store is the source.
    Http::preventStrayRequests();

    $repository = app(JurisdictionRepository::class);
    $calculator = app(TaxCalculator::class);

    $assessment = $calculator->assess(new TaxQuery(
        amount: Money::of('100.00', 'DKK'),
        pricing: Pricing::Exclusive,
        place: $repository->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
        category: TaxClass::GeneralGoods,
    ));

    expect((string) $assessment->rate->percentage)->toBe('25')
        ->and((string) $assessment->tax->getAmount())->toBe('25.00')
        ->and($assessment->rate->source)->toBe('cbox-tax')
        ->and($assessment->rate->provenance?->version)->toBe($version);
})->group('e2e');

it('reports what is installed, offline', function (): void {
    $this->artisan('tax:data:sync', ['--region' => ['gcc'], '--no-boundaries' => true])->assertSuccessful();

    $this->artisan('tax:data:status', ['--offline' => true])
        ->expectsOutputToContain('Pricing version')
        ->assertSuccessful();
})->group('e2e');
