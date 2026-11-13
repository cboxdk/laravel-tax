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

/*
 * A signed object-storage URL: the register may answer a large file with ONE redirect,
 * which is followed only to an allowed origin, without the token, and only for a file
 * whose published sha256 the download is then checked against.
 */

const R2_ORIGIN = 'https://register.fixture-account.r2.cloudflarestorage.com';

function r2Fetcher(Factory $http, array $origins = [R2_ORIGIN]): SectionFetcher
{
    return new SectionFetcher($http, token: 'secret-register-token', redirectOrigins: $origins);
}

function r2Target(): string
{
    return sys_get_temp_dir().'/cbox-tax-r2-'.getmypid().'/'.bin2hex(random_bytes(4)).'.json';
}

it('downloads exactly as before when the register serves the file itself', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake(['data.cboxtax.com/*' => $http->response('{"zip":{}}')]);
    $to = r2Target();

    // A hash that does not match is not consulted on the register's own answer.
    expect(r2Fetcher($http)->download('/api/v1/releases/x/boundaries/KS', $to, str_repeat('0', 64)))->toBe(10)
        ->and(file_get_contents($to))->toBe('{"zip":{}}');

    $http->assertSentCount(1);
    $http->assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer secret-register-token'));
});

it('follows one redirect to an allowed origin, without the token, and checks the hash', function (): void {
    $body = '{"zip":{"66044":[1]}}';
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake([
        'data.cboxtax.com/*' => $http->response('', 302, ['Location' => R2_ORIGIN.'/releases/x/boundaries/ks.json?X-Amz-Signature=abc']),
        'register.fixture-account.r2.cloudflarestorage.com/*' => $http->response($body),
    ]);
    $to = r2Target();

    expect(r2Fetcher($http)->download('/api/v1/releases/x/boundaries/KS', $to, hash('sha256', $body)))->toBe(strlen($body))
        ->and(file_get_contents($to))->toBe($body);

    $http->assertSentCount(2);
    $http->assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://data.cboxtax.com/')
        && $request->hasHeader('Authorization', 'Bearer secret-register-token'));
    $http->assertSent(fn ($request): bool => str_starts_with($request->url(), R2_ORIGIN.'/')
        && ! $request->hasHeader('Authorization'));
});

it('never forwards the register token to the redirected origin', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $seen = [];
    $http->fake(function ($request) use ($http, &$seen) {
        $seen[$request->url()] = $request->header('Authorization');

        return str_starts_with($request->url(), 'https://data.cboxtax.com/')
            ? $http->response('', 307, ['Location' => R2_ORIGIN.'/a.json'])
            : $http->response('{}');
    });

    r2Fetcher($http)->download('/api/v1/releases/x/boundaries/KS', r2Target(), hash('sha256', '{}'));

    expect($seen)->toBe([
        'https://data.cboxtax.com/api/v1/releases/x/boundaries/KS' => ['Bearer secret-register-token'],
        R2_ORIGIN.'/a.json' => [],
    ]);
});

it('refuses a redirect to an origin that is not allowed, and says which', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake(['data.cboxtax.com/*' => $http->response('', 302, ['Location' => 'https://other-account.r2.cloudflarestorage.com/ks.json'])]);
    $to = r2Target();

    expect(fn (): ?int => r2Fetcher($http)->download('/api/v1/releases/x/boundaries/KS', $to, hash('sha256', '{}')))
        ->toThrow(RateSourceUnavailable::class, 'https://other-account.r2.cloudflarestorage.com, which is not in tax.register.redirect_origins ('.R2_ORIGIN.')');

    $http->assertSentCount(1);
    expect(file_exists($to))->toBeFalse();
});

it('refuses every redirect when no origin is configured, as before', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake(['data.cboxtax.com/*' => $http->response('', 302, ['Location' => R2_ORIGIN.'/ks.json'])]);

    expect(fn (): ?int => r2Fetcher($http, [])->download('/api/v1/releases/x/boundaries/KS', r2Target(), hash('sha256', '{}')))
        ->toThrow(RateSourceUnavailable::class, 'not in tax.register.redirect_origins (none configured)');

    $http->assertSentCount(1);
});

it('refuses a second redirect', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake([
        'data.cboxtax.com/*' => $http->response('', 302, ['Location' => R2_ORIGIN.'/first.json']),
        'register.fixture-account.r2.cloudflarestorage.com/*' => $http->response('', 302, ['Location' => R2_ORIGIN.'/second.json']),
    ]);
    $to = r2Target();

    expect(fn (): ?int => r2Fetcher($http)->download('/api/v1/releases/x/boundaries/KS', $to, hash('sha256', '{}')))
        ->toThrow(RateSourceUnavailable::class, 'more than once');

    $http->assertSentCount(2);
    expect(file_exists($to))->toBeFalse();
});

it('refuses, and removes, a redirected download whose hash does not match', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake([
        'data.cboxtax.com/*' => $http->response('', 302, ['Location' => R2_ORIGIN.'/ks.json']),
        'register.fixture-account.r2.cloudflarestorage.com/*' => $http->response('{"tampered":true}'),
    ]);
    $to = r2Target();
    $published = hash('sha256', '{"zip":{}}');

    expect(fn (): ?int => r2Fetcher($http)->download('/api/v1/releases/x/boundaries/KS', $to, $published))
        ->toThrow(RateSourceUnavailable::class, 'integrity check failed for /api/v1/releases/x/boundaries/KS: the register published sha256 '.$published);

    expect(file_exists($to))->toBeFalse();
});

it('refuses a redirect for a file with no published hash, even to an allowed origin', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake(['data.cboxtax.com/*' => $http->response('', 302, ['Location' => R2_ORIGIN.'/regions/us.json'])]);

    expect(fn (): ?int => r2Fetcher($http)->download('/api/v1/releases/x/regions/us', r2Target()))
        ->toThrow(RateSourceUnavailable::class, 'only a document with a published sha256 may be redirected');
    expect(fn (): array => r2Fetcher($http)->json('/api/v1/releases/latest'))
        ->toThrow(RateSourceUnavailable::class, 'only a document with a published sha256 may be redirected');

    $http->assertSentCount(2);
});

it('refuses a redirect to plain http or with credentials in it', function (string $location): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake(['data.cboxtax.com/*' => $http->response('', 302, ['Location' => $location])]);

    expect(fn (): ?int => r2Fetcher($http, [R2_ORIGIN, 'https://register.fixture-account.r2.cloudflarestorage.com'])->download('/x', r2Target(), hash('sha256', '{}')))
        ->toThrow(RateSourceUnavailable::class, 'not an https URL this sync can follow');
})->with([
    'http' => 'http://register.fixture-account.r2.cloudflarestorage.com/ks.json',
    'userinfo' => 'https://user:pass@register.fixture-account.r2.cloudflarestorage.com/ks.json',
    'not a URL' => 'ftp://register.fixture-account.r2.cloudflarestorage.com/ks.json',
]);

it('refuses an allow-list entry that is not an https origin', function (string $entry): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake(['data.cboxtax.com/*' => $http->response('', 302, ['Location' => R2_ORIGIN.'/ks.json'])]);

    expect(fn (): ?int => r2Fetcher($http, [$entry])->download('/x', r2Target(), hash('sha256', '{}')))
        ->toThrow(RateSourceUnavailable::class, 'is not an https origin');
})->with([
    'http' => 'http://register.fixture-account.r2.cloudflarestorage.com',
    'with a path' => R2_ORIGIN.'/bucket',
    'a bare host' => 'register.fixture-account.r2.cloudflarestorage.com',
]);

it('matches an allowed origin by scheme, host and port, with the default port implied', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake([
        'data.cboxtax.com/*' => $http->response('', 301, ['Location' => 'https://Register.Fixture-Account.R2.cloudflarestorage.com:443/ks.json']),
        'register.fixture-account.r2.cloudflarestorage.com*' => $http->response('{}'),
    ]);

    expect(r2Fetcher($http, [R2_ORIGIN.'/'])->download('/x', r2Target(), hash('sha256', '{}')))->toBe(2);
});

it('reads the allowed origins from config, as a list or comma-separated', function (mixed $configured): void {
    config()->set('tax.register.token', 'secret-register-token');
    config()->set('tax.register.redirect_origins', $configured);
    app()->forgetInstance(SectionFetcher::class);

    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake([
        'data.cboxtax.com/*' => $http->response('', 302, ['Location' => R2_ORIGIN.'/ks.json']),
        'register.fixture-account.r2.cloudflarestorage.com/*' => $http->response('{}'),
    ]);

    expect(app(SectionFetcher::class)->download('/x', r2Target(), hash('sha256', '{}')))->toBe(2);
})->with([
    'comma-separated' => ' https://elsewhere.example , '.R2_ORIGIN.' ',
    'array' => [[R2_ORIGIN]],
]);

it('takes a section over one redirect when its published hash matches the body', function (): void {
    $body = '{"regimes":[{"code":"eu"}]}';
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake([
        'data.cboxtax.com/*' => $http->response('', 302, ['Location' => R2_ORIGIN.'/sections/regimes.json']),
        'register.fixture-account.r2.cloudflarestorage.com/*' => $http->response($body),
    ]);

    expect(r2Fetcher($http)->json('/api/v1/releases/x/sections/regimes', strtoupper(hash('sha256', $body))))
        ->toBe(['regimes' => [['code' => 'eu']]]);
    $http->assertSent(fn ($request): bool => str_starts_with($request->url(), R2_ORIGIN.'/') && ! $request->hasHeader('Authorization'));
});

it('refuses a redirected section whose body does not match its published hash', function (string $method): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake([
        'data.cboxtax.com/*' => $http->response('', 302, ['Location' => R2_ORIGIN.'/sections/rules.json']),
        'register.fixture-account.r2.cloudflarestorage.com/*' => $http->response('{"rules":[{"tampered":true}]}'),
    ]);

    expect(fn (): ?array => r2Fetcher($http)->{$method}('/api/v1/releases/x/sections/rules', hash('sha256', '{"rules":[]}')))
        ->toThrow(RateSourceUnavailable::class, 'integrity check failed for /api/v1/releases/x/sections/rules');
})->with(['json', 'jsonIfPublished']);

it('reads a section the register serves itself exactly as before, hash or not', function (): void {
    $http = app(Factory::class);
    $http->preventStrayRequests();
    $http->fake(['data.cboxtax.com/*' => $http->response(['rules' => []])]);

    expect(r2Fetcher($http)->json('/api/v1/releases/x/sections/rules', str_repeat('a', 64)))->toBe(['rules' => []])
        ->and(r2Fetcher($http)->json('/api/v1/releases/x/sections/rules'))->toBe(['rules' => []]);
});
