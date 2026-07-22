<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\RateSource\ChainTaxRateSource;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\RateSource\TedbRateSource;
use Illuminate\Http\Client\Factory;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->fixture = __DIR__.'/../Fixtures/tedb-rates.json';
});

it('reads a standard rate from a TEDB dataset file (no network)', function () {
    $source = new TedbRateSource(new Factory, $this->fixture);

    $dk = $source->rateFor($this->geo->find(new CountryCode('DK')), TaxCategory::Standard);

    expect($dk)->not->toBeNull()
        ->and((string) $dk->percentage)->toBe('25')
        ->and($dk->kind)->toBe(RateKind::Standard)
        ->and($dk->source)->toBe('tedb');
});

it('resolves a reduced band by category from the TEDB dataset', function () {
    $source = new TedbRateSource(new Factory, $this->fixture);

    $frDigital = $source->rateFor($this->geo->find(new CountryCode('FR')), TaxCategory::DigitalService);
    $frStandard = $source->rateFor($this->geo->find(new CountryCode('FR')), TaxCategory::Standard);

    expect((string) $frDigital->percentage)->toBe('5.5')
        ->and($frDigital->kind)->toBe(RateKind::Reduced)
        ->and((string) $frStandard->percentage)->toBe('20')
        ->and($frStandard->kind)->toBe(RateKind::Standard);
});

it('returns null for a country absent from the TEDB dataset', function () {
    $source = new TedbRateSource(new Factory, $this->fixture);

    expect($source->rateFor($this->geo->find(new CountryCode('ES')), TaxCategory::Standard))->toBeNull();
});

it('returns null when the TEDB source path does not exist', function () {
    $source = new TedbRateSource(new Factory, __DIR__.'/../Fixtures/does-not-exist.json');

    expect($source->rateFor($this->geo->find(new CountryCode('DK')), TaxCategory::Standard))->toBeNull();
});

it('reads a TEDB dataset from an http URL', function () {
    $http = new Factory;
    /** @var string $body */
    $body = file_get_contents($this->fixture);
    $http->fake(['*' => $http->response($body)]);

    $source = new TedbRateSource($http, 'https://tedb.example/rates.json');

    expect((string) $source->rateFor($this->geo->find(new CountryCode('DE')), TaxCategory::Standard)->percentage)->toBe('19');
});

it('falls back to the static snapshot when TEDB has no rate for the country', function () {
    // The fixture omits ES; the chain must fall through to the static snapshot.
    $chain = new ChainTaxRateSource([
        new TedbRateSource(new Factory, $this->fixture),
        new StaticTaxRateSource,
    ]);

    $es = $chain->rateFor($this->geo->find(new CountryCode('ES')), TaxCategory::Standard);

    expect((string) $es->percentage)->toBe('21') // static snapshot ES
        ->and($es->source)->toBe('static');
});

it('activates the TEDB chain only when tax.tedb.url is configured', function () {
    // Isolate from the US dataset (enabled by default): with no feeds configured,
    // the bound source is the plain static snapshot.
    config()->set('tax.us_tax_data.enabled', false);

    // Unconfigured (default): the bound source is the plain static snapshot.
    expect($this->app->make(TaxRateSource::class))->toBeInstanceOf(StaticTaxRateSource::class);

    // Configured: the bound source composes TEDB -> static via a chain.
    $this->app->forgetInstance(TaxRateSource::class);
    config()->set('tax.tedb.url', $this->fixture);

    expect($this->app->make(TaxRateSource::class))->toBeInstanceOf(ChainTaxRateSource::class);
});
