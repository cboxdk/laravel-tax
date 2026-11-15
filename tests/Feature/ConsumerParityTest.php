<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\TaxRate;

/*
 * Two places where this engine and the register's own resolver read one release
 * differently (cbox-tax `cadastre:consumer-check` on release 2026.11.15-457, 11 pairs).
 */

beforeEach(function (): void {
    config()->set('tax.register.store', config()->string('tax.register.store').'/consumer-parity');

    $book = [['kind' => 'applies_only_to', 'says' => 'a printed book or an update of a printed book;', 'predicate' => ['fact' => 'product.publicationKind', 'op' => 'eq', 'value' => 'book', 'says' => 'a printed book or an update of a printed book;']]];

    FakeRegister::at(config()->string('tax.register.store'))
        ->category('goods')
        ->category('goods.publications', 'goods')
        ->category('goods.publications.book', 'goods.publications')
        ->category('goods.clothing', 'goods')
        ->category('goods.clothing.sport', 'goods.clothing')
        ->category('goods.clothing.diapers_childrens', 'goods.clothing')
        ->category('goods.food', 'goods')
        ->category('goods.food.basic', 'goods.food')
        ->rate('ca:CA', '5', from: '1991-01-01')
        ->rate('ca:ON', '13', 'combined', from: '2010-07-01')
        ->rate('ca:ON', '5', 'combined', 'goods.publications.book', from: '2010-07-01', extra: ['conditions' => $book])
        ->rate('ca:ON', '0', 'zero', 'goods.food.basic', from: '2010-07-01')
        ->rate('us:RI', '7', from: '1990-07-01', extra: ['taxType' => 'sales_use'])
        ->rate('us:RI', '0', 'exempt', 'goods.clothing', from: '2012-10-01', extra: ['taxType' => 'sales_use'])
        ->rate('us:RI', '0', 'exempt', 'goods.clothing.diapers_childrens', from: '2012-10-01', extra: ['taxType' => 'sales_use'])
        ->rule('us:RI', 'price_exemption', ['category' => 'goods.clothing', 'capAmount' => '250.00', 'capCurrency' => 'USD', 'capIsExclusive' => false, 'above' => 'excess_taxable'], from: '2012-10-01')
        ->install();
});

function parityRate(string $country, string $subdivision, string $key): ?TaxRate
{
    $place = app(JurisdictionRepository::class)->find(new CountryCode($country), new SubdivisionCode($subdivision));

    if ($place === null) {
        throw new RuntimeException($subdivision.' is not a place the engine knows.');
    }

    return app(RegisterRateSource::class)->rateForKey($place, $key, null, new DateTimeImmutable('2026-11-15'));
}

it('reads a harmonised province\'s own all-in total for a category, its conditions open', function (): void {
    // Ontario's point-of-sale rebate: a printed book carries the 5% federal part only.
    $book = parityRate('CA', 'CA-ON', 'goods.publications.book');

    expect((string) $book?->percentage)->toBe('5')
        ->and($book?->limitedBy)->toBe(RateLimit::ConditionsUnevaluated)
        // Nothing filed for the category: the harmonised rate.
        ->and((string) parityRate('CA', 'CA-ON', 'goods.clothing')?->percentage)->toBe('13')
        // A band of the province's own still answers for itself.
        ->and((string) parityRate('CA', 'CA-ON', 'goods.food.basic')?->percentage)->toBe('0');
});

it('applies a price cap to the exemption it was written for, not to a narrower one of its own', function (): void {
    // The cap is on clothing: a coat reaches the clothing exemption and is taxed above $250.
    expect((string) parityRate('US', 'US-RI', 'goods.clothing')?->percentage)->toBe('7')
        // A leaf with no row of its own climbs to the capped exemption.
        ->and((string) parityRate('US', 'US-RI', 'goods.clothing.sport')?->percentage)->toBe('7')
        // Diapers are exempt on a row of their own, under no cap.
        ->and((string) parityRate('US', 'US-RI', 'goods.clothing.diapers_childrens')?->percentage)->toBe('0');
});
