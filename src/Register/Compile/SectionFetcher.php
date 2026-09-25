<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Compile;

use Cbox\Tax\Exceptions\DatasetUnreadable;
use Cbox\Tax\Exceptions\RateSourceUnavailable;
use Cbox\Tax\Register\Reader\RegisterCompatibility;
use Cbox\Tax\Register\Reader\Shape;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Talks to data.cboxtax.com: lists releases, reads the small sections inline, and
 * streams the large ones to disk.
 *
 * NOTHING HERE IS CALLED WHILE PRICING. Every method belongs to `tax:data:sync`. The
 * engine reads a compiled store on local disk and makes no network call at all, which
 * is the point of compiling one.
 *
 * Reads are unauthenticated because the register is: the licence moves the line from
 * who may read to what may be done with it, so there is no key to hold and none to
 * leak. Requests accept gzip — the whole register is 1.8 MB compressed against 54 MB
 * of JSON — and send `If-None-Match`, which the register answers from a manifest
 * without touching a file.
 */
readonly class SectionFetcher
{
    private const string SOURCE = 'cbox-tax';

    public function __construct(
        private Factory $http,
        private string $baseUrl = 'https://data.cboxtax.com',
        private int $timeout = 120,
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
     * @return array<string, mixed>
     */
    public function json(string $path): array
    {
        try {
            $response = $this->http->timeout($this->timeout)->acceptJson()->get($this->url($path));
        } catch (Throwable $e) {
            throw RateSourceUnavailable::transport(self::SOURCE, $e->getMessage());
        }

        if (! $response->successful()) {
            throw RateSourceUnavailable::badResponse(self::SOURCE, $response->status());
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
     * @return array<string, mixed>|null
     */
    public function jsonIfPublished(string $path): ?array
    {
        try {
            $response = $this->http->timeout($this->timeout)->acceptJson()->get($this->url($path));
        } catch (Throwable $e) {
            throw RateSourceUnavailable::transport(self::SOURCE, $e->getMessage());
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw RateSourceUnavailable::badResponse(self::SOURCE, $response->status());
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
     */
    public function download(string $path, string $to): ?int
    {
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
            $response = $this->http
                ->connectTimeout(30)
                ->timeout(0)
                ->withOptions([
                    'read_timeout' => 60,
                    'curl' => defined('CURLOPT_LOW_SPEED_LIMIT') ? [
                        CURLOPT_LOW_SPEED_LIMIT => 1024,
                        CURLOPT_LOW_SPEED_TIME => 60,
                    ] : [],
                ])
                ->sink($to)
                ->get($this->url($path));
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

        $size = @filesize($to);

        return $size === false ? 0 : $size;
    }

    /**
     * An absolute URL is taken as it stands; anything else hangs off the base.
     *
     * The register's boundary listing gives both an absolute `url` and a relative
     * `artifact` for each file, and for the ZIP artifacts the two do not agree: the
     * `url` answers 200 and the `artifact` path 404s. Accepting either here lets the
     * caller prefer whichever the publisher actually serves.
     */
    private function url(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
