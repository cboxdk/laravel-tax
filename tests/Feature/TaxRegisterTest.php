<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\TaxRegister;
use Cbox\Tax\Enums\LocalResolution;
use Cbox\Tax\Enums\ThresholdOperator;
use Cbox\Tax\Exceptions\DatasetNotInstalled;
use Cbox\Tax\Exceptions\UnknownCategory;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\RateRecord;
use Cbox\Tax\ValueObjects\RegisterCategory;

/*
 * What an application may ask the register directly. An application read the store
 * through the class that reads it — its release, its categories, which US states need
 * an address, the EU's threshold, and the rate records a release files — and every
 * change to that class was a change to the application. This is the face that does
 * not change with the store.
 */

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/tax-register');
});

function taxRegister(): TaxRegister
{
    app()->forgetInstance(TaxRegister::class);
    app()->forgetInstance(RegisterDataset::class);
    app()->forgetInstance(StoreLayout::class);
    app()->forgetInstance(StorePointer::class);

    return app(TaxRegister::class);
}

it('names the release the engine prices with, and its vocabulary', function (): void {
    FakeRegister::at(config('tax.register.store'), '2026.09.25-310')
        ->rate('eu:DK', '25')
        ->category('goods')
        ->category('goods.publications', 'goods')
        ->category('goods.publications.book', 'goods.publications')
        ->install();

    $register = taxRegister();

    expect($register->release()?->version)->toBe('2026.09.25-310')
        ->and(array_map(fn (RegisterCategory $c): array => [$c->key, $c->parent], $register->categories()))->toBe([
            ['goods', null],
            ['goods.publications', 'goods'],
            ['goods.publications.book', 'goods.publications'],
        ]);

    $register->assertCategoryPublished('goods.publications.book');

    expect(fn () => $register->assertCategoryPublished('goods.publications.bok'))->toThrow(UnknownCategory::class);
});

it('says what a US state needs, as the register states it', function (): void {
    FakeRegister::at(config('tax.register.store'))
        ->rate('us:TX', '6.25')
        ->usLocal('TX', needs: 'address')
        ->usLocal('PA', needs: 'county')
        ->usLocal('DE', needs: 'state')
        ->install();

    $register = taxRegister();

    expect($register->localResolution('TX'))->toBe(LocalResolution::Address)
        ->and($register->localResolution('pa'))->toBe(LocalResolution::County)
        ->and($register->localResolution('DE')?->needsGeocoding())->toBeFalse()
        // A state the release says nothing about is not "needs nothing".
        ->and($register->localResolution('WY'))->toBeNull();
});

it('reports the EU distance-sales threshold with both of its periods', function (): void {
    FakeRegister::at(config('tax.register.store'))
        ->rate('eu:DK', '25')
        ->rule('eu', 'threshold', ['conditions' => [
            ['amount' => '10000.00', 'currency' => 'EUR', 'measuredOver' => 'previous_calendar_year', 'amountOperator' => 'exceeds'],
            ['amount' => '10000.00', 'currency' => 'EUR', 'measuredOver' => 'calendar_year', 'amountOperator' => 'exceeds'],
        ], 'conditionsCombinator' => 'any_of'], from: '2021-07-01')
        ->install();

    $threshold = taxRegister()->distanceSalesThreshold(new DateTimeImmutable('2026-09-28'));

    expect((string) $threshold?->amount)->toBe('EUR 10000.00')
        ->and($threshold?->operator)->toBe(ThresholdOperator::Exceeds)
        ->and($threshold?->measuredOver)->toBe(['previous_calendar_year', 'calendar_year'])
        // Before the OSS reform there was no such rule.
        ->and(taxRegister()->distanceSalesThreshold(new DateTimeImmutable('2021-06-30')))->toBeNull();
});

it('lists the rate records a release files, and those of an older installed release', function (): void {
    $store = config('tax.register.store');
    FakeRegister::at($store, '2026.09.01-1')->rate('eu:DK', '25')->rate('us:TX', '6.25')->install();
    FakeRegister::at($store, '2026.09.28-2')->rate('eu:DK', '25')->rate('eu:DK', '0', 'zero', 'goods.publications.newspaper')->rate('us:TX', '6.25')->install();

    $geo = app(JurisdictionRepository::class);
    $denmark = $geo->find(new CountryCode('DK'));
    $texas = $geo->find(new CountryCode('US'), new SubdivisionCode('US-TX'));
    $register = taxRegister();

    $now = $register->rateRecords($denmark);
    $then = $register->rateRecords($denmark, '2026.09.01-1');

    expect(array_map(fn (RateRecord $r): string => $r->kind.' '.$r->percentage.' '.($r->category ?? '-'), $now))->toBe(['standard 25 -', 'zero 0 goods.publications.newspaper'])
        ->and(count($then))->toBe(1)
        ->and(array_map(fn (RateRecord $r): string => $r->jurisdiction, $register->rateRecords($texas)))->toBe(['us:TX'])
        ->and(fn (): array => $register->rateRecords($denmark, '2020.01.01-9'))->toThrow(DatasetNotInstalled::class);
});
