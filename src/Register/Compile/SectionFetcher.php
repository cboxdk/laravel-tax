<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Compile;

use Cbox\Tax\Exceptions\RateSourceUnavailable;
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
final readonly class SectionFetcher
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
            $response = $this->http->timeout($this->timeout)->sink($to)->get($this->url($path));
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
