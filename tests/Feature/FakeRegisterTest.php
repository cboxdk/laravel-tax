<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\Register\Sources\RegisterTaxability;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\Testing\FakeRegister;

/*
 * The MECHANICS of resolution, against a store built to say exactly one thing.
 *
 * Nothing here is evidence about what a country charges — the numbers are invented
 * on purpose, so that a test about date containment cannot start passing because a
 * real rate moved. Claims about real rates live in the e2e group, against the live
 * register.
 */

function fakeRegister(): FakeRegister
{
    return FakeRegister::at(test()->store);
}

function fakeSource(): RegisterRateSource
{
    $layout = new StoreLayout(test()->store);

    return new RegisterRateSource(new RegisterDataset($layout, new StorePointer($layout)));
}

function fakePlace(string $country): ?Jurisdiction
{
    return app(JurisdictionRepository::class)->find(new CountryCode($country));
}

beforeEach(function (): void {
    $this->store = sys_get_temp_dir().'/cbox-tax-fake-'.getmypid().'-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    $remove = function (string $d) use (&$remove): void {
        foreach (is_dir($d) ? (array) scandir($d) : [] as $e) {
            if (! is_string($e) || $e === '.' || $e === '..') {
                continue;
            }
            is_dir("$d/$e") ? $remove("$d/$e") : @unlink("$d/$e");
        }
        @rmdir($d);
    };
    $remove($this->store);
});

it('takes a rate whose window contains the date, not one that merely has no end', function (): void {
    // 4 863 live rates in the real register end 2078-12-31 — the Streamlined matrix's
    // way of writing "no end". Filtering on `until === null` drops every one of them,
    // Oklahoma's own state rate among them.
    fakeRegister()
        ->rate('eu:DK', '20', from: '2000-01-01', until: '2019-12-31')
        ->rate('eu:DK', '25', from: '2020-01-01', until: '2078-12-31')
        ->install();

    $old = fakeSource()->rateFor(fakePlace('DK'), TaxClass::GeneralGoods, new DateTimeImmutable('2015-06-01'));
    $now = fakeSource()->rateFor(fakePlace('DK'), TaxClass::GeneralGoods, new DateTimeImmutable('2026-06-01'));

    expect((string) $old?->percentage)->toBe('20')
        ->and((string) $now?->percentage)->toBe('25');
});

it('ignores a levy the supplier bears', function (): void {
    // A digital services tax is a charge on the supplier's turnover. Summed into a
    // cart it overcharges the customer and under-declares the liability at once.
    fakeRegister()
        ->rate('eu:DK', '25')
        ->rate('eu:DK', '3', category: 'services.digital', extra: ['borneBy' => 'supplier'])
        ->install();

    $rate = fakeSource()->rateFor(fakePlace('DK'), TaxClass::DigitalService);

    expect((string) $rate?->percentage)->toBe('25');
});

it('climbs to a parent category, and does not call that an inference', function (): void {
    fakeRegister()
        ->rate('eu:DK', '25')
        ->rate('eu:DK', '6', kind: 'reduced', category: 'goods.publications')
        ->install();

    $rate = fakeSource()->rateFor(fakePlace('DK'), TaxClass::Book);

    expect((string) $rate?->percentage)->toBe('6')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative)
        ->and($rate?->limitedBy)->toBeNull();
});

it('refuses a band when a category has two live answers, and takes the standard rate', function (): void {
    fakeRegister()
        ->rate('eu:DK', '25')
        ->rate('eu:DK', '10', kind: 'reduced', category: 'goods.food')
        ->rate('eu:DK', '5', kind: 'reduced', category: 'goods.food')
        ->install();

    $rate = fakeSource()->rateFor(fakePlace('DK'), TaxClass::Groceries);

    // Never the lower of the two: the standard rate is the direction a customer can
    // be refunded from.
    expect((string) $rate?->percentage)->toBe('25')
        ->and($rate?->limitedBy)->toBe(RateLimit::HeadingAmbiguous);
});

it('takes the longest classification, and marks a shortened one as inferred', function (): void {
    // The real register has 95 live cases where a chapter and a subheading beneath it
    // disagree. Austria taxes food at 10% under CN 04 and carves CN 0401 10 out.
    fakeRegister()
        ->rate('eu:DK', '25')
        ->rate('eu:DK', '10', kind: 'reduced', category: 'goods.food', classification: '04')
        ->rate('eu:DK', '5', kind: 'reduced', category: 'goods.food', classification: '040110')
        ->install();

    $exact = fakeSource()->rateForCommodity(fakePlace('DK'), TaxClass::Groceries, '0401 10');
    $chapter = fakeSource()->rateForCommodity(fakePlace('DK'), TaxClass::Groceries, '04');
    $deeper = fakeSource()->rateForCommodity(fakePlace('DK'), TaxClass::Groceries, '0499 99 00');

    expect((string) $exact?->percentage)->toBe('5')
        ->and($exact?->limitedBy)->toBeNull()
        ->and((string) $chapter?->percentage)->toBe('10')
        ->and($chapter?->limitedBy)->toBeNull()
        // Nothing published at this code, so the chapter answered — an inference, and
        // one a more specific code could contradict.
        ->and((string) $deeper?->percentage)->toBe('10')
        ->and($deeper?->limitedBy)->toBe(RateLimit::ClassificationInferred)
        ->and($deeper?->confidence)->toBe(Confidence::Derived);
});

it('reads a price exemption the way the statute writes it', function (): void {
    $layout = new StoreLayout(test()->store);

    fakeRegister()
        ->rate('us:MA', '6.25')
        ->rate('us:NY', '4')
        ->rule('us:MA', 'price_exemption', ['category' => 'goods.clothing', 'capAmount' => '175.00', 'capCurrency' => 'USD', 'above' => 'excess_taxable'])
        ->rule('us:NY', 'price_exemption', ['category' => 'goods.clothing', 'capAmount' => '110.00', 'capCurrency' => 'USD', 'above' => 'whole_item_taxable'])
        ->install();

    $taxability = new RegisterTaxability(new RegisterDataset($layout, new StorePointer($layout)));
    $coat = Money::of('200.00', 'USD');

    $ma = $taxability->determine(fakeUsPlace('US-MA'), TaxClass::Clothing, $coat);
    $ny = $taxability->determine(fakeUsPlace('US-NY'), TaxClass::Clothing, $coat);

    // The same field with opposite meanings: Massachusetts taxes the excess, New York
    // taxes the whole garment once it reaches the line.
    expect((string) $ma->taxableBase($coat)->getAmount())->toBe('25.00')
        ->and((string) $ny->taxableBase($coat)->getAmount())->toBe('200.00');
});

function fakeUsPlace(string $state): ?Jurisdiction
{
    return app(JurisdictionRepository::class)->find(
        new CountryCode('US'),
        new SubdivisionCode($state),
    );
}
