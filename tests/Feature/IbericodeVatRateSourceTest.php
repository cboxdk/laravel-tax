<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\RateSource\CachingTaxRateSource;
use Cbox\Tax\RateSource\ChainTaxRateSource;
use Cbox\Tax\RateSource\IbericodeVatRateSource;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Illuminate\Http\Client\Factory;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->fixture = __DIR__.'/../Fixtures/ibericode-vat-rates.json';
});

it('reads a standard rate from the ibericode dataset file (no network)', function () {
    $source = new IbericodeVatRateSource(new Factory, $this->fixture);

    $dk = $source->rateFor($this->geo->find(new CountryCode('DK')), TaxCategory::Standard);

    expect($dk)->not->toBeNull()
        ->and((string) $dk->percentage)->toBe('25')
        ->and($dk->kind)->toBe(RateKind::Standard)
        ->and($dk->source)->toBe('ibericode-vat-rates');
});

it('selects the rate period in force at a given date', function () {
    $source = new IbericodeVatRateSource(new Factory, $this->fixture);
    $de = $this->geo->find(new CountryCode('DE'));

    // 2021+ period is 19%; the 2020-07-01 temporary cut was 16%.
    $now = $source->rateFor($de, TaxCategory::Standard, new DateTimeImmutable('2022-01-01'));
    $cut = $source->rateFor($de, TaxCategory::Standard, new DateTimeImmutable('2020-09-01'));

    expect((string) $now->percentage)->toBe('19')
        ->and((string) $cut->percentage)->toBe('16');
});

it('resolves a reduced tier only when the operator maps a category to it', function () {
    // Default: no category->tier map, so digital services resolve the standard rate
    // (the dataset does not label which tier a category belongs to).
    $default = new IbericodeVatRateSource(new Factory, $this->fixture);
    $fr = $this->geo->find(new CountryCode('FR'));

    expect((string) $default->rateFor($fr, TaxCategory::DigitalService)->percentage)->toBe('20');

    // Operator-supplied mapping surfaces the real reduced tier from the dataset.
    $mapped = new IbericodeVatRateSource(new Factory, $this->fixture, ['digital_service' => 'reduced1']);

    $band = $mapped->rateFor($fr, TaxCategory::DigitalService);
    expect((string) $band->percentage)->toBe('5.5')
        ->and($band->kind)->toBe(RateKind::Reduced);
});

it('returns null for a country absent from the dataset', function () {
    $source = new IbericodeVatRateSource(new Factory, $this->fixture);

    expect($source->rateFor($this->geo->find(new CountryCode('ES')), TaxCategory::Standard))->toBeNull();
});

it('returns null when the source path does not exist', function () {
    $source = new IbericodeVatRateSource(new Factory, __DIR__.'/../Fixtures/does-not-exist.json');

    expect($source->rateFor($this->geo->find(new CountryCode('DK')), TaxCategory::Standard))->toBeNull();
});

it('reads the dataset from an http URL', function () {
    $http = new Factory;
    /** @var string $body */
    $body = file_get_contents($this->fixture);
    $http->fake(['*' => $http->response($body)]);

    $source = new IbericodeVatRateSource($http, 'https://raw.githubusercontent.com/ibericode/vat-rates/master/vat-rates.json');

    expect((string) $source->rateFor($this->geo->find(new CountryCode('DK')), TaxCategory::Standard)->percentage)->toBe('25');
});

it('falls back to the static snapshot when the feed has no rate for the country', function () {
    $chain = new ChainTaxRateSource([
        new IbericodeVatRateSource(new Factory, $this->fixture),
        new StaticTaxRateSource,
    ]);

    $es = $chain->rateFor($this->geo->find(new CountryCode('ES')), TaxCategory::Standard);

    expect((string) $es->percentage)->toBe('21') // static snapshot ES
        ->and($es->source)->toBe('static');
});

it('activates the EU VAT feed chain only when tax.eu_vat is enabled', function () {
    // Unconfigured (default): the bound source is the plain static snapshot.
    expect($this->app->make(TaxRateSource::class))->toBeInstanceOf(StaticTaxRateSource::class);

    $this->app->forgetInstance(TaxRateSource::class);
    config()->set('tax.eu_vat.enabled', true);
    config()->set('tax.eu_vat.url', $this->fixture); // local path: not wrapped in cache

    expect($this->app->make(TaxRateSource::class))->toBeInstanceOf(ChainTaxRateSource::class);
});

it('wraps a remote EU VAT feed URL in the cache', function () {
    config()->set('tax.eu_vat.enabled', true);
    config()->set('tax.eu_vat.url', 'https://raw.githubusercontent.com/ibericode/vat-rates/master/vat-rates.json');
    $this->app->forgetInstance(TaxRateSource::class);

    $source = $this->app->make(TaxRateSource::class);
    expect($source)->toBeInstanceOf(ChainTaxRateSource::class);

    // The first composed source is the cached feed.
    $reflected = new ReflectionProperty(ChainTaxRateSource::class, 'sources');
    $sources = $reflected->getValue($source);
    expect($sources[0])->toBeInstanceOf(CachingTaxRateSource::class);
});
