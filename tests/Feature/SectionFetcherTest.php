<?php

declare(strict_types=1);

use Cbox\Tax\Exceptions\RateSourceUnavailable;
use Cbox\Tax\Register\Compile\SectionFetcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Sleep;

/*
 * The register allows 120 requests a minute per address, shared by every sync from it.
 * A 429 used to end a sync part-way through.
 */

beforeEach(function (): void {
    Sleep::fake();
});

it('waits out a rate limit as the register asks, then carries on', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fakeSequence('data.cboxtax.com/*')
        ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '7'])
        ->push(['version' => '2026.10.03-343']);

    expect(new SectionFetcher($http, token: 'fixture-register-token')->json('/api/v1/releases/latest'))->toBe(['version' => '2026.10.03-343']);

    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(7)->seconds()]);
});

it('streams a file after a rate limit lifts', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fakeSequence('data.cboxtax.com/*')
        ->push('', 503)
        ->push('{"rates":[]}');
    $to = sys_get_temp_dir().'/cbox-tax-fetch-'.getmypid().'/rates.json';

    expect(new SectionFetcher($http, token: 'fixture-register-token')->download('/api/v1/releases/x/regions/eu', $to))->toBe(12)
        ->and(file_get_contents($to))->toBe('{"rates":[]}');
});

it('gives up on a limit that does not lift, and caps how long it waits', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake(['data.cboxtax.com/*' => $http->response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '3600'])]);

    expect(fn (): array => new SectionFetcher($http, token: 'fixture-register-token')->json('/api/v1/releases/latest'))
        ->toThrow(RateSourceUnavailable::class, 'HTTP 429');

    $http->assertSentCount(4);
    Sleep::assertSequence([Sleep::for(60)->seconds(), Sleep::for(60)->seconds(), Sleep::for(60)->seconds()]);
});

it('does not retry an answer that is not about load', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake(['data.cboxtax.com/*' => $http->response([], 500)]);

    expect(fn (): array => new SectionFetcher($http, token: 'fixture-register-token')->json('/api/v1/releases/latest'))
        ->toThrow(RateSourceUnavailable::class, 'HTTP 500');

    $http->assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('authenticates every register read and refuses missing credentials', function (): void {
    $http = app(Factory::class);
    $http->fake(['data.cboxtax.com/*' => $http->response(['version' => 'pinned-release'])]);
    expect(fn (): array => new SectionFetcher($http)->json('/api/v1/releases/latest'))->toThrow(RateSourceUnavailable::class);
    $http->assertNothingSent();
    new SectionFetcher($http, token: 'secret-register-token')->json('/api/v1/releases/latest');
    $http->assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-register-token'));
});

it('does not send register credentials to a foreign artifact URL or follow redirects', function (): void {
    $http = app(Factory::class);
    $http->fake(['data.cboxtax.com/*' => $http->response('', 302, ['Location' => 'https://untrusted.example/artifact'])]);
    $fetcher = new SectionFetcher($http, token: 'secret-register-token');
    expect(fn (): array => $fetcher->json('https://untrusted.example/artifact'))->toThrow(RateSourceUnavailable::class);
    $http->assertNothingSent();
    expect(fn (): array => $fetcher->json('/api/v1/releases/latest'))->toThrow(RateSourceUnavailable::class);
    $http->assertSentCount(1);
});

it('opens every register connection within the connect timeout', function (): void {
    $http = app(Factory::class);
    $seen = [];
    $http->fake(function ($request, array $options) use ($http, &$seen) {
        $seen[] = $options['connect_timeout'] ?? null;

        return $http->response(['version' => '2026.10.03-343']);
    });

    $fetcher = new SectionFetcher($http, token: 'fixture-register-token', connectTimeout: 4);
    $fetcher->json('/api/v1/releases/latest');
    $fetcher->download('/api/v1/releases/x/regions/eu', sys_get_temp_dir().'/cbox-tax-ct-'.getmypid().'/eu.json');

    expect($seen)->toBe([4.0, 4.0]);
});
