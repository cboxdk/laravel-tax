<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRegister;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\LocalityScheme;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterBoundaries;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\Testing\DatasetComparison;
use Cbox\Tax\Testing\DatasetConformance;
use Cbox\Tax\Testing\DatasetDifference;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\Tests\Fixtures\CopiedDataset;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Filesystem\Filesystem;

uses(DatasetConformance::class);

/*
 * The dataset is a contract with the file store as its default. These are the
 * promises that makes: nothing changes for a host that binds nothing, a host that
 * binds its own dataset is read everywhere, and the kit that proves a dataset is
 * right is run here on the file store itself.
 */

function fileStore(): RegisterDataset
{
    return app(RegisterDataset::class);
}

it('reads the file store unless a host binds another dataset', function (): void {
    expect(app(RegisterData::class))->toBe(fileStore())
        ->and(fileStore())->toBeInstanceOf(RegisterData::class);
});

it('still builds the file store by hand, as code written before the contract does', function (): void {
    $layout = new StoreLayout(fileStore()->storeRoot());
    $byHand = new RegisterDataset($layout, new StorePointer($layout));

    expect($byHand->version())->toBe(fileStore()->version())
        ->and($byHand->ratesFor('eu:DK'))->toBe(fileStore()->ratesFor('eu:DK'))
        ->and(DatasetComparison::between($byHand, fileStore())->identical())->toBeTrue();

    // And the boundary resolver over a layout and a version, as before.
    $boundaries = new RegisterBoundaries($layout, (string) $byHand->version(), $byHand);
    $place = app(JurisdictionRepository::class)
        ->find(new CountryCode('US'), new SubdivisionCode('US-KS'))
        ?->withLocality(new LocalityCode(new SubdivisionCode('US-KS'), LocalityScheme::Zip9->value, '66101-6200'));

    expect($place)->not->toBeNull()
        ->and($boundaries->authoritiesFor($place))->not->toBeNull();

    expect($boundaries->authoritiesFor($place))
        ->toBe(app(LocalAuthorityResolver::class)->authoritiesFor($place));
});

it('passes its own conformance check on the file store', function (): void {
    $this->assertDatasetConforms(fileStore());
});

it('passes the conformance check on a dataset that is not the file store', function (): void {
    $this->assertDatasetConforms(CopiedDataset::of(fileStore()));
});

it('finds no difference between two datasets holding the same release', function (): void {
    $comparison = DatasetComparison::between(fileStore(), CopiedDataset::of(fileStore()));

    expect($comparison->identical())->toBeTrue($comparison->summary())
        ->and($comparison->count())->toBe(0)
        ->and($comparison->compared()['codes'])->toBeGreaterThan(50)
        ->and($comparison->compared()['rates'])->toBeGreaterThan(50)
        ->and($comparison->compared()['boundaryRecords'])->toBeGreaterThan(0)
        ->and($comparison->summary())->toContain('No differences.');
});

it('names every place two datasets differ, and groups them by cause', function (): void {
    $other = sys_get_temp_dir().'/cbox-tax-compare-'.getmypid().'-'.bin2hex(random_bytes(4));

    try {
        FakeRegister::at($other, (string) fileStore()->version())
            ->rate('eu:DK', '24', from: '1990-01-01')
            ->rate('eu:FR', '21', from: '1990-01-01')
            ->rule('eu', 'threshold', ['conditions' => []], '2021-07-01')
            ->boundary('KS', '66101', ['state:20'])
            ->install();

        $layout = new StoreLayout($other);
        $comparison = DatasetComparison::between(fileStore(), new RegisterDataset($layout, new StorePointer($layout)));

        expect($comparison->identical())->toBeFalse()
            ->and($comparison->patterns())->toHaveKey('rates[*][*].percentage')
            ->and($comparison->patterns()['rates[*][*].percentage'])->toBe(2)
            ->and($comparison->patterns())->toHaveKey('shards.count')
            ->and($comparison->patterns())->toHaveKey('boundary[*].keys.count');

        $percentages = array_values(array_filter(
            $comparison->differences(),
            static fn (DatasetDifference $difference): bool => $difference->where === 'rates[eu:DK][0].percentage',
        ));

        expect($percentages)->toHaveCount(1)
            ->and($percentages[0]->left)->toBe('25')
            ->and($percentages[0]->right)->toBe('24')
            ->and((string) $percentages[0])->toBe('rates[eu:DK][0].percentage: 25 != 24');
    } finally {
        (new Filesystem)->deleteDirectory($other);
    }
});

it('keeps counting past the differences it keeps in full', function (): void {
    $other = sys_get_temp_dir().'/cbox-tax-compare-'.getmypid().'-'.bin2hex(random_bytes(4));

    try {
        FakeRegister::at($other, (string) fileStore()->version())->rate('eu:DK', '24')->install();
        $layout = new StoreLayout($other);

        $comparison = DatasetComparison::between(fileStore(), new RegisterDataset($layout, new StorePointer($layout)), boundaries: false, keep: 3);

        expect($comparison->differences())->toHaveCount(3)
            ->and($comparison->count())->toBeGreaterThan(3)
            ->and(array_sum($comparison->patterns()))->toBe($comparison->count())
            ->and($comparison->compared())->not->toHaveKey('boundaryFiles');
    } finally {
        (new Filesystem)->deleteDirectory($other);
    }
});

it('prices from the dataset a host binds, everywhere the engine reads the register', function (): void {
    $own = sys_get_temp_dir().'/cbox-tax-own-'.getmypid().'-'.bin2hex(random_bytes(4));

    try {
        // A register that disagrees with the file store about Denmark, held by a
        // dataset that is not the file store.
        FakeRegister::at($own, '2026.02.02-2')->rate('eu:DK', '12', from: '1990-01-01')->install();
        $layout = new StoreLayout($own);
        $bound = CopiedDataset::of(new RegisterDataset($layout, new StorePointer($layout)));

        app()->instance(RegisterData::class, $bound);

        $denmark = app(JurisdictionRepository::class)->find(new CountryCode('DK'));
        $assessment = app(TaxCalculator::class)->assess(new TaxQuery(
            Money::of('100', 'DKK'),
            Pricing::Exclusive,
            $denmark,
            CustomerType::Consumer,
            new SellerRegistrations(new CountryCode('DK')),
        ));

        expect((string) $assessment->tax->getAmount())->toBe('12.00')
            ->and(app(TaxRegister::class)->release()?->version)->toBe('2026.02.02-2')
            // The file store is still there under its own name, untouched.
            ->and(fileStore()->version())->not->toBe('2026.02.02-2');
    } finally {
        (new Filesystem)->deleteDirectory($own);
    }
});

it('says what pricing reads when a store command runs beside another dataset', function (): void {
    app()->instance(RegisterData::class, CopiedDataset::of(fileStore()));

    $this->artisan('tax:data:verify')
        ->expectsOutputToContain('not from the file store. This command manages the file store only');

    $this->artisan('tax:data:status', ['--offline' => true])
        ->expectsOutputToContain('The engine prices from '.CopiedDataset::class.' (memory)')
        ->expectsOutputToContain('Store: '.fileStore()->storeRoot());

    $this->artisan('tax:data:prune')
        ->expectsOutputToContain('This command manages the file store only')
        ->assertSuccessful();
});

it('says nothing extra when the file store is what pricing reads', function (): void {
    $this->artisan('tax:data:status', ['--offline' => true])
        ->doesntExpectOutputToContain('This command manages the file store only');
});

it('boots and runs the store commands when the bound dataset cannot be opened', function (): void {
    app()->bind(RegisterData::class, static fn (): never => throw new RuntimeException('the database is away'));

    $this->artisan('tax:data:prune')
        ->expectsOutputToContain('could not be opened (the database is away)')
        ->assertSuccessful();
});
