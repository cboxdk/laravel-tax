<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\RateSource\CachingTaxRateSource;
use Cbox\Tax\RateSource\ChainTaxRateSource;
use Cbox\Tax\RateSource\RemoteRateSource;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\ValueObjects\TaxRate;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
});

it('resolves a rate from a remote JSON feed (number or {standard})', function () {
    $http = new Factory;
    $http->fake(['*' => $http->response(['DK' => 25, 'DE' => ['standard' => 19]])]);

    $source = new RemoteRateSource($http, 'https://feed.example/rates.json', 'tedb');

    $dk = $source->rateFor($this->geo->find(new CountryCode('DK')), TaxCategory::Standard);
    $de = $source->rateFor($this->geo->find(new CountryCode('DE')), TaxCategory::Standard);

    expect((string) $dk->percentage)->toBe('25')
        ->and($dk->source)->toBe('tedb')
        ->and((string) $de->percentage)->toBe('19');
});

it('returns null from a remote feed when the service fails', function () {
    $http = new Factory;
    $http->fake(['*' => $http->response('', 503)]);

    $source = new RemoteRateSource($http, 'https://feed.example/rates.json');

    expect($source->rateFor($this->geo->find(new CountryCode('DK')), TaxCategory::Standard))->toBeNull();
});

it('chains sources and returns the first hit', function () {
    $chain = new ChainTaxRateSource([new StaticTaxRateSource([]), new StaticTaxRateSource(['DK' => '25'])]);

    expect((string) $chain->rateFor($this->geo->find(new CountryCode('DK')), TaxCategory::Standard)->percentage)->toBe('25');
});

it('caches the resolved rate so the inner source is queried once', function () {
    $inner = new class implements TaxRateSource
    {
        public int $calls = 0;

        public function rateFor(Jurisdiction $jurisdiction, TaxCategory $category, ?DateTimeImmutable $at = null): ?TaxRate
        {
            $this->calls++;

            return new TaxRate('25');
        }
    };

    $caching = new CachingTaxRateSource($inner, new Repository(new ArrayStore));
    $dk = $this->geo->find(new CountryCode('DK'));

    $caching->rateFor($dk, TaxCategory::Standard);
    $caching->rateFor($dk, TaxCategory::Standard);

    expect($inner->calls)->toBe(1);
});
