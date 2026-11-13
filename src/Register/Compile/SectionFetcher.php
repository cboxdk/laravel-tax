<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Compile;

use Cbox\Tax\Contracts\TaxRegister;
use Cbox\Tax\Exceptions\DatasetUnreadable;
use Cbox\Tax\Exceptions\RateSourceUnavailable;
use Cbox\Tax\Register\Reader\RegisterCompatibility;
use Cbox\Tax\Register\Reader\Shape;
use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Talks to data.cboxtax.com: lists releases, reads the small sections inline, and
 * streams the large ones to disk.
 *
 * NOTHING HERE IS CALLED WHILE PRICING. Every method belongs to `tax:data:sync`. The
 * engine reads a compiled store on local disk and makes no network call at all, which
 * is the point of compiling one.
 *
 * Reads require an explicit server-side integration token. Artifact URLs are pinned
 * to the configured origin, and the token goes to that origin and nowhere else.
 *
 * ONE REDIRECT, TO A TRUSTED ORIGIN, FOR A FILE WITH A PUBLISHED HASH. The register
 * may hand a large file over to object storage — a short-lived signed URL — instead
 * of streaming the bytes itself. Such a redirect is followed only when its target's
 * origin is on `tax.register.redirect_origins` (empty by default, which refuses every
 * redirect as before), only once, only over https, never with the token, and only
 * for a download whose sha256 the register published: the bytes then come from
 * somebody other than the register, and the hash is what ties them back to it. A
 * redirect for anything else is refused and says why.
 * Requests accept gzip — the whole register is 1.8 MB compressed against 54 MB
 * of JSON — and send `If-None-Match`, which the register answers from a manifest
 * without touching a file.
 *
 * @internal Changes with the register's format. Applications ask the register through
 *           {@see TaxRegister}.
 */
readonly class SectionFetcher
{
    private const string SOURCE = 'cbox-tax';

    /** Tries per request when the register says to slow down, the first included. */
    private const int ATTEMPTS = 4;

    /** The longest a sync waits on one `Retry-After` before trying again. */
    private const int MAX_WAIT_SECONDS = 60;

    /** The answers that send a client somewhere else. 304 is not one of them. */
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(
        private Factory $http,
        private string $baseUrl = 'https://data.cboxtax.com',
        private int $timeout = 120,
        private ?string $token = null,
        /** Seconds to open a connection, for every request including downloads. */
        private float $connectTimeout = 5.0,
        /**
         * Origins a download may be redirected to, as `https://host[:port]`. Empty
         * refuses every redirect.
         *
         * @var list<string>
         */
        private array $redirectOrigins = [],
    ) {}

    /**
     * Resolve `latest` to a concrete version, ONCE, before anything is fetched.
     *
     * The register publishes several times a day — four releases landed on
     * 2026-09-15 alone — so asking for `latest` per section would assemble a store
     * out of two different registers, with a rate from one and the rule that scopes
     * it from the other. Everything after this point names a fixed version.
     */
    public function resolve(string $version): string
    {
        if ($version !== 'latest') {
            return $version;
        }

        $release = $this->json('/api/v1/releases/latest');
        $resolved = $release['version'] ?? null;

        if (! is_string($resolved) || $resolved === '') {
            throw RateSourceUnavailable::unreadable(self::SOURCE);
        }

        return $resolved;
    }

    /**
     * The newest release this package can read, and what `latest` is if that differs.
     *
     * `latest` FOLLOWS THE REGISTER, and the register moves ahead of the package: a
     * release on a schema this package has not been reviewed against cannot be
     * compiled, and refusing it outright used to freeze the installed data until
     * somebody upgraded — every correction the register published on the old schema in
     * the meantime left behind. So a sync that asked for `latest` takes the newest
     * release it can read and says what it skipped. A release asked for BY NAME still
     * refuses: the operator wanted that one.
     *
     * @return array{version: string, latest: string, latestSchema: ?string}
     */
    public function newestReadable(): array
    {
        // The common case costs what it always did: one small request, and `latest`
        // is readable. Only a register that has moved ahead is walked back.
        $head = $this->json('/api/v1/releases/latest');
        $headVersion = Shape::text($head['version'] ?? null);

        if ($headVersion === null) {
            throw RateSourceUnavailable::unreadable(self::SOURCE);
        }

        // Read from the release itself where the pointer does not carry it — the same
        // document the compile reads next, so the decision and the compile agree.
        $headSchema = Shape::text($head['schemaVersion'] ?? null)
            ?? Shape::text($this->json('/api/v1/releases/'.$headVersion)['schemaVersion'] ?? null);

        if (RegisterCompatibility::reads($headSchema)) {
            return ['version' => $headVersion, 'latest' => $headVersion, 'latestSchema' => $headSchema];
        }

        $releases = $this->releases();

        usort($releases, static fn (array $a, array $b): int => strcmp(
            Shape::text($b['publishedAt'] ?? null) ?? '',
            Shape::text($a['publishedAt'] ?? null) ?? '',
        ));

        $latest = Shape::text($releases[0]['version'] ?? null);

        if ($latest === null) {
            throw RateSourceUnavailable::unreadable(self::SOURCE);
        }

        $latestSchema = Shape::text($releases[0]['schemaVersion'] ?? null);

        foreach ($releases as $release) {
            $version = Shape::text($release['version'] ?? null);

            if ($version !== null && RegisterCompatibility::reads($release['schemaVersion'] ?? null)) {
                return ['version' => $version, 'latest' => $latest, 'latestSchema' => $latestSchema];
            }
        }

        throw DatasetUnreadable::unsupportedSchema($latest, $latestSchema, RegisterCompatibility::supported());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function releases(): array
    {
        $body = $this->json('/api/v1/releases');
        $releases = $body['releases'] ?? null;

        if (! is_array($releases)) {
            return [];
        }

        $out = [];

        foreach ($releases as $release) {
            if (is_array($release)) {
                /** @var array<string, mixed> $release */
                $out[] = $release;
            }
        }

        return $out;
    }

    /**
     * A small document, decoded.
     *
     * `$sha256` is the hash the release publishes for it (`sha256.sections.*`). With
     * one, a single redirect to an allowed origin is followed and the body is checked
     * against it before it is decoded; without one, a redirect is refused.
     *
     * @return array<string, mixed>
     */
    public function json(string $path, ?string $sha256 = null): array
    {
        $sha256 = self::digest($sha256);

        try {
            [$response, $redirected] = $this->send(fn (PendingRequest $request): PendingRequest => $request->connectTimeout($this->connectTimeout)->timeout($this->timeout)->acceptJson(), $this->url($path), verifiable: $sha256 !== null);
        } catch (RateSourceUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw RateSourceUnavailable::transport(self::SOURCE, $e->getMessage());
        }

        if (! $response->successful()) {
            throw RateSourceUnavailable::badResponse(self::SOURCE, $response->status());
        }

        if ($redirected) {
            self::verify($path, $sha256, hash('sha256', $response->body()));
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw RateSourceUnavailable::unreadable(self::SOURCE);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A section an older release may not publish: null on 404, a refusal on anything
     * else. Only for advisory documents — a section the engine prices from is never
     * optional, and a missing one must stop the compile.
     *
     * `$sha256` works as it does for {@see self::json()}.
     *
     * @return array<string, mixed>|null
     */
    public function jsonIfPublished(string $path, ?string $sha256 = null): ?array
    {
        $sha256 = self::digest($sha256);

        try {
            [$response, $redirected] = $this->send(fn (PendingRequest $request): PendingRequest => $request->connectTimeout($this->connectTimeout)->timeout($this->timeout)->acceptJson(), $this->url($path), verifiable: $sha256 !== null);
        } catch (RateSourceUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw RateSourceUnavailable::transport(self::SOURCE, $e->getMessage());
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw RateSourceUnavailable::badResponse(self::SOURCE, $response->status());
        }

        if ($redirected) {
            self::verify($path, $sha256, hash('sha256', $response->body()));
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw RateSourceUnavailable::unreadable(self::SOURCE);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Stream a document straight to disk, never through memory.
     *
     * `regions/us` is 48.8 MB and `json_decode` on it peaks at 315 MB. It reaches
     * this package as bytes on disk that {@see JsonArrayStream} then walks a record
     * at a time, and at no point does the document exist as PHP arrays.
     *
     * Returns the number of bytes written, or null when the register says the file
     * is missing — a state with no boundary artifact answers 404, which is an
     * answer about coverage rather than a failure.
     *
     * `$sha256` is the hash the register published for the file (the boundary
     * listing's `hash`, or the release's `sha256.regions.*`). Without one a redirect
     * is refused; with one, a redirected download is checked against it and refused,
     * and removed, if the bytes differ. A file the register serves itself is taken as
     * it was before.
     */
    public function download(string $path, string $to, ?string $sha256 = null): ?int
    {
        $sha256 = self::digest($sha256);
        $directory = dirname($to);

        if (! is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        try {
            // A DOWNLOAD IS ABANDONED WHEN IT STALLS, NOT WHEN IT IS LARGE. A fixed
            // total timeout failed street indexes at random: Arkansas's is 31 MB and
            // Georgia's 36, the store serves them anywhere between 12 KB/s and a few
            // MB/s, and at 120 seconds anything slower than 260 KB/s was cut off
            // mid-file. So the connection has to open in reasonable time, and the
            // transfer is aborted only if it moves less than a kilobyte a second for a
            // full minute — never merely for taking a while.
            [$response, $redirected] = $this->send(fn (PendingRequest $request): PendingRequest => $request
                ->connectTimeout($this->connectTimeout)
                ->timeout(0)
                ->withOptions([
                    'read_timeout' => 60,
                    'curl' => defined('CURLOPT_LOW_SPEED_LIMIT') ? [
                        CURLOPT_LOW_SPEED_LIMIT => 1024,
                        CURLOPT_LOW_SPEED_TIME => 60,
                    ] : [],
                ])
                ->sink($to), $this->url($path), verifiable: $sha256 !== null);
        } catch (RateSourceUnavailable $e) {
            @unlink($to);

            throw $e;
        } catch (Throwable $e) {
            @unlink($to);

            throw RateSourceUnavailable::transport(self::SOURCE, $e->getMessage());
        }

        if ($response->status() === 404) {
            @unlink($to);

            return null;
        }

        if (! $response->successful()) {
            @unlink($to);

            throw RateSourceUnavailable::badResponse(self::SOURCE, $response->status());
        }

        if ($redirected) {
            try {
                self::verify($path, $sha256, @hash_file('sha256', $to));
            } catch (RateSourceUnavailable $e) {
                @unlink($to);

                throw $e;
            }
        }

        $size = @filesize($to);

        return $size === false ? 0 : $size;
    }

    /**
     * One request to the register, and at most one redirect after it.
     *
     * The first request carries the token and goes to the register. A redirect is
     * followed only when `$verifiable` (the caller can check what comes back), only
     * to an allowed https origin, and with a request built fresh from the factory —
     * so the token, which was added to the first request alone, is not on it. A second
     * redirect is refused rather than followed.
     *
     * @param  Closure(PendingRequest): PendingRequest  $configure
     * @return array{0: Response, 1: bool} the response, and whether it came from a redirect
     */
    private function send(Closure $configure, string $url, bool $verifiable): array
    {
        $response = $this->patiently(fn (): Response => $configure($this->request())->get($url));

        if (! in_array($response->status(), self::REDIRECTS, true)) {
            return [$response, false];
        }

        $target = $this->redirectTarget($url, $response->header('Location'));

        if (! $verifiable) {
            throw RateSourceUnavailable::refusedRedirect(self::SOURCE, sprintf(
                'the register redirected %s to %s, and nothing was published to verify it against — only a document with a published sha256 may be redirected',
                $url,
                self::origin($target) ?? $target,
            ));
        }

        $followed = $this->patiently(fn (): Response => $configure($this->http->withoutRedirecting())->get($target));

        if (in_array($followed->status(), self::REDIRECTS, true)) {
            throw RateSourceUnavailable::refusedRedirect(self::SOURCE, sprintf(
                'the register redirected %s more than once; one redirect is followed, never two',
                $url,
            ));
        }

        return [$followed, true];
    }

    /**
     * Where a redirect points, if it points somewhere this sync trusts.
     *
     * Absolute, or root-relative to the register (which must then itself be listed);
     * https; no credentials in the URL; and an origin listed in
     * `tax.register.redirect_origins`, compared exactly — scheme, host and port.
     */
    private function redirectTarget(string $from, string $location): string
    {
        $location = trim($location);

        if ($location === '') {
            throw RateSourceUnavailable::refusedRedirect(self::SOURCE, 'the register answered a redirect with no Location');
        }

        if (str_starts_with($location, '/') && ! str_starts_with($location, '//')) {
            $location = (self::origin($from) ?? '').$location;
        }

        $parts = parse_url($location);
        $origin = self::origin($location);

        if (! is_array($parts) || $origin === null || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])) {
            throw RateSourceUnavailable::refusedRedirect(self::SOURCE, sprintf(
                'the register redirected %s to %s, which is not an https URL this sync can follow',
                $from,
                $location,
            ));
        }

        $allowed = [];

        foreach ($this->redirectOrigins as $entry) {
            $normal = self::origin($entry);

            if ($normal === null || ! str_starts_with($normal, 'https://') || ! in_array(parse_url($entry, PHP_URL_PATH) ?? '', ['', '/'], true)) {
                throw RateSourceUnavailable::refusedRedirect(self::SOURCE, sprintf(
                    'tax.register.redirect_origins lists "%s", which is not an https origin (https://host[:port])',
                    $entry,
                ));
            }

            $allowed[] = $normal;
        }

        if (! in_array($origin, $allowed, true)) {
            throw RateSourceUnavailable::refusedRedirect(self::SOURCE, sprintf(
                'the register redirected %s to %s, which is not in tax.register.redirect_origins (%s)',
                $from,
                $origin,
                $allowed === [] ? 'none configured' : implode(', ', $allowed),
            ));
        }

        return $location;
    }

    /** A published sha256 as lowercase hex, or null for anything that is not one. */
    private static function digest(?string $sha256): ?string
    {
        return is_string($sha256) && preg_match('/^[0-9a-f]{64}$/i', $sha256) === 1 ? strtolower($sha256) : null;
    }

    /**
     * Refuse what came over a redirect unless it is what the register published.
     *
     * Only reached for a redirected answer, and a redirect is only followed with a
     * hash in hand — so a null `$expected` here is a refusal, never a pass.
     */
    private static function verify(string $path, ?string $expected, string|false $actual): void
    {
        if ($expected === null || $actual === false || ! hash_equals($expected, $actual)) {
            throw RateSourceUnavailable::integrityMismatch(self::SOURCE, $path, $expected ?? 'none', $actual === false ? null : $actual);
        }
    }

    /**
     * `scheme://host[:port]`, lowercased, with the scheme's default port dropped —
     * or null for anything that is not an absolute http(s) URL.
     */
    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port === null || $port === $default ? '' : ':'.$port);
    }

    /**
     * Send, and send again while the register says to slow down.
     *
     * The register allows 120 requests a minute per address, shared by every sync
     * from it — several servers deploying at once, a deploy and its scheduler — and a
     * 429 cut a sync off part-way. A 429 or 503 is waited out as the register's
     * `Retry-After` asks, up to a minute a time, a few times; anything else, and a
     * limit that does not lift, is answered as before.
     *
     * @param  callable(): Response  $send
     */
    private function patiently(callable $send): Response
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $send();

            if (! in_array($response->status(), [429, 503], true) || $attempt >= self::ATTEMPTS) {
                return $response;
            }

            $after = $response->header('Retry-After');
            Sleep::for(max(1, min(self::MAX_WAIT_SECONDS, is_numeric($after) ? (int) $after : 2 ** $attempt)))->seconds();
        }
    }

    /**
     * An absolute URL is taken as it stands; anything else hangs off the base.
     *
     * The register's boundary listing gives both an absolute `url` and a relative
     * `artifact` for each file, and for the ZIP artifacts the two do not agree: the
     * `url` answers 200 and the `artifact` path 404s. Accepting either here lets the
     * caller prefer whichever the publisher actually serves.
     */
    private function request(): PendingRequest
    {
        if ($this->token === null || trim($this->token) === '') {
            throw RateSourceUnavailable::transport(self::SOURCE, 'Register integration token is not configured.');
        }

        return $this->http->withToken($this->token)->withoutRedirecting();
    }

    private function url(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $base = parse_url($this->baseUrl);
            $target = parse_url($path);
            if (! is_array($base) || ! is_array($target)
                || ($target['scheme'] ?? null) !== ($base['scheme'] ?? null)
                || ($target['host'] ?? null) !== ($base['host'] ?? null)
                || ($target['port'] ?? null) !== ($base['port'] ?? null)
                || isset($target['user']) || isset($target['pass'])) {
                throw RateSourceUnavailable::transport(self::SOURCE, 'Register artifact URL is outside the configured origin.');
            }

            return $path;
        }

        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
