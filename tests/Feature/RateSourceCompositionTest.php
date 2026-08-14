<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Exceptions\RateSourceUnavailable;
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

    $dk = $source->rateFor($this->geo->find(new CountryCode('DK')), TaxClass::GeneralGoods);
    $de = $source->rateFor($this->geo->find(new CountryCode('DE')), TaxClass::GeneralGoods);

    expect((string) $dk->percentage)->toBe('25')
        ->and($dk->source)->toBe('tedb')
        ->and((string) $de->percentage)->toBe('19');
});

it('reports a failed remote feed as unavailable rather than as no rate', function () {
    $http = new Factory;
    $http->fake(['*' => $http->response('', 503)]);

    $source = new RemoteRateSource($http, 'https://feed.example/rates.json');

    expect(fn () => $source->rateFor($this->geo->find(new CountryCode('DK')), TaxClass::GeneralGoods))
        ->toThrow(RateSourceUnavailable::class);
});

it('chains sources and returns the first hit', function () {
    $chain = new ChainTaxRateSource([new StaticTaxRateSource([]), new StaticTaxRateSource(['DK' => '25'])]);

    expect((string) $chain->rateFor($this->geo->find(new CountryCode('DK')), TaxClass::GeneralGoods)->percentage)->toBe('25');
});

it('caches the resolved rate so the inner source is queried once', function () {
    $inner = new class implements TaxRateSource
    {
        public int $calls = 0;

        public function rateFor(Jurisdiction $jurisdiction, TaxClass $category, ?DateTimeImmutable $at = null): ?TaxRate
        {
            $this->calls++;

            return new TaxRate('25');
        }
    };

    $caching = new CachingTaxRateSource($inner, new Repository(new ArrayStore));
    $dk = $this->geo->find(new CountryCode('DK'));

    $caching->rateFor($dk, TaxClass::GeneralGoods);
    $caching->rateFor($dk, TaxClass::GeneralGoods);

    expect($inner->calls)->toBe(1);
});

// ---- A broken source is not an empty one ----------------------------------
//
// `null` was carrying two meanings that need opposite handling. "I have no rate
// for this jurisdiction" is a normal answer from a source with limited scope, and
// the chain rightly moves on. "My endpoint timed out" is not an answer at all,
// and moving on quietly reached the static snapshot and billed from it.
//
// It was never literally silent — the fallback carries Confidence::Derived rather
// than Authoritative. But Derived is also what a correctly coarse resolution looks
// like, so an operator could not tell a broken feed from a normal day.

/** A source that always fails the way a dead endpoint does. */
function brokenSource(): TaxRateSource
{
    return new class implements TaxRateSource
    {
        public function rateFor(Jurisdiction $j, TaxClass $c, ?DateTimeImmutable $at = null): ?TaxRate
        {
            throw RateSourceUnavailable::transport('flaky-feed', 'connection refused');
        }
    };
}

it('still answers from a fallback when the preferred source is down', function () {
    // Falling back is right — the snapshot is real, reviewed data. What was wrong
    // was doing it invisibly.
    $chain = new ChainTaxRateSource([brokenSource(), new StaticTaxRateSource(['DK' => '25'])]);

    $rate = $chain->rateFor($this->geo->find(new CountryCode('DK')), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('25');
});

it('marks that fallback as degraded, so it cannot pass for a clean answer', function () {
    $chain = new ChainTaxRateSource([brokenSource(), new StaticTaxRateSource(['DK' => '25'])]);

    $rate = $chain->rateFor($this->geo->find(new CountryCode('DK')), TaxClass::GeneralGoods);

    expect($rate?->confidence)->toBe(Confidence::LowConfidence)
        // The reason travels with the rate into the assessment. A log line would
        // have been the only other record, and nobody reads those in time.
        ->and($rate?->source)->toContain('connection refused');
});

it('leaves a clean fallback alone', function () {
    // A source with nothing to say is not a fault, and the chain moving past it is
    // the behaviour that has always been correct. Nothing about that result is
    // degraded, and marking it so would cry wolf on every normal lookup.
    $chain = new ChainTaxRateSource([new StaticTaxRateSource([]), new StaticTaxRateSource(['DK' => '25'])]);

    expect($chain->rateFor($this->geo->find(new CountryCode('DK')), TaxClass::GeneralGoods)?->confidence)
        ->not->toBe(Confidence::LowConfidence);
});

it('refuses outright when nothing answered and something was broken', function () {
    // The original bug in its purest form. Returning null here tells the caller
    // "there is no rate for this jurisdiction" — a statement about the world —
    // when the truth is "we could not find out", a statement about us. The engine
    // then denies for the wrong stated reason, and the operator never learns their
    // feed is down.
    $chain = new ChainTaxRateSource([brokenSource(), new StaticTaxRateSource([])]);

    expect(fn () => $chain->rateFor($this->geo->find(new CountryCode('DK')), TaxClass::GeneralGoods))
        ->toThrow(RateSourceUnavailable::class);
});

it('names the source that failed, not just that something did', function () {
    $chain = new ChainTaxRateSource([brokenSource(), new StaticTaxRateSource([])]);

    try {
        $chain->rateFor($this->geo->find(new CountryCode('DK')), TaxClass::GeneralGoods);
        $this->fail('the chain should have refused');
    } catch (RateSourceUnavailable $e) {
        expect($e->source)->toBe('flaky-feed')
            ->and($e->getMessage())->toContain('connection refused');
    }
});
