<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\EuTaxData\EuTaxDataset;
use Cbox\Tax\RateSource\EuTaxDatasetRateSource;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->source = new EuTaxDatasetRateSource(new EuTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/eu-tax-dataset',
    ));
});

function hungary(): Jurisdiction
{
    return test()->geo->find(new CountryCode('HU'));
}

// ---------------------------------------------------------------------------
// The heading cannot answer; the code can
// ---------------------------------------------------------------------------

it('still charges the standard rate when no code is given', function () {
    // Hungary rates foodstuffs at 5% and 18% at once and nothing settles which.
    // This is the behaviour before codes and it is unchanged: the safe fallback,
    // labelled Derived so a caller can see a better answer exists.
    $rate = $this->source->rateFor(hungary(), TaxClass::Groceries);

    expect((string) $rate?->percentage)->toBe('27')
        ->and($rate?->confidence)->toBe(Confidence::Derived);
});

it('resolves the reduced rate from the supply\'s CN code', function () {
    // cn:01022110 — live pure-bred breeding cattle, in Hungary's 5% animal-sector
    // scope. The heading gives nothing; the code gives 5%.
    $rate = $this->source->rateForCommodity(hungary(), TaxClass::Groceries, 'cn:01022110');

    expect((string) $rate?->percentage)->toBe('5')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative)
        ->and($rate?->source)->toContain('cn:01022110');
});

it('resolves the OTHER rate under the same heading', function () {
    // cn:1806 — chocolate, in the 18% scope. Same heading, same country, same call,
    // different answer: which is the whole point of scoping by code.
    expect((string) $this->source->rateForCommodity(hungary(), TaxClass::Groceries, 'cn:1806')?->percentage)
        ->toBe('18');
});

// ---------------------------------------------------------------------------
// How a caller is allowed to write the code
// ---------------------------------------------------------------------------

it('accepts the spaces the tariff prints for readability', function (string $written) {
    expect((string) $this->source->rateForCommodity(hungary(), TaxClass::Groceries, $written)?->percentage)->toBe('5');
})->with([
    'prefixed and packed' => ['cn:01022110'],
    'bare, assumed CN' => ['01022110'],
    'as the tariff prints it' => ['0102 21 10'],
    'shouted' => ['CN:01022110'],
]);

it('reaches the chapter when the caller quotes a code nothing scopes precisely', function () {
    // Longest-prefix, as tariff classification works. Hungary scopes `cn:1806`
    // (chocolate, the whole heading); a caller quoting the eight-digit
    // `cn:18063100` for filled chocolate blocks has to reach it.
    expect((string) $this->source->rateForCommodity(hungary(), TaxClass::Groceries, 'cn:18063100')?->percentage)
        ->toBe('18');
});

it('does not let a CPA code answer a CN question', function () {
    // The schemes collide as bare strings — `32` is a CPA division and a CN chapter
    // — so the prefix is part of the key rather than a label beside it.
    $rate = $this->source->rateForCommodity(hungary(), TaxClass::Groceries, 'cpa:0102');

    expect((string) $rate?->percentage)->toBe('27')
        ->and($rate?->confidence)->toBe(Confidence::Derived);
});

// ---------------------------------------------------------------------------
// A code refines; it never restricts
// ---------------------------------------------------------------------------

it('falls back to the honest standard rate for a code nothing scopes', function () {
    $rate = $this->source->rateForCommodity(hungary(), TaxClass::Groceries, 'cn:99999999');

    expect((string) $rate?->percentage)->toBe('27')
        ->and($rate?->confidence)->toBe(Confidence::Derived);
});

it('ignores a code on a heading that was already settled', function () {
    // Accommodation is a single 18% band in Hungary. A caller passing a code
    // opportunistically must not have it change a settled answer — that is what
    // lets them pass one without knowing whether this country needed it.
    $withCode = $this->source->rateForCommodity(hungary(), TaxClass::Accommodation, 'cn:01022110');
    $without = $this->source->rateFor(hungary(), TaxClass::Accommodation);

    expect((string) $withCode?->percentage)->toBe((string) $without?->percentage);
});

it('ignores an empty code rather than treating it as a lookup', function () {
    expect((string) $this->source->rateForCommodity(hungary(), TaxClass::Groceries, '   ')?->percentage)
        ->toBe('27');
});

// ---------------------------------------------------------------------------
// End to end, through the engine the caller actually uses
// ---------------------------------------------------------------------------

it('carries the code from the query all the way to the rate', function () {
    $this->app->instance(TaxRateSource::class, $this->source);

    $assessment = $this->app->make(TaxCalculator::class)->assess(new TaxQuery(
        amount: Money::of('100.00', 'HUF'),
        pricing: Pricing::Exclusive,
        place: hungary(),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('HU')),
        category: TaxClass::Groceries,
        commodityCode: 'cn:01022110',
    ));

    // The seam this proves is the one that was silently broken before: the code
    // has to survive TaxQuery, the chain, and the caching wrapper to reach here.
    expect((string) $assessment->rate?->percentage)->toBe('5');
});

it('declares itself a commodity source so the chain will pass codes to it', function () {
    expect($this->source)->toBeInstanceOf(CommodityRateSource::class);
});
