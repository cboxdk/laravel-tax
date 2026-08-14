<?php

declare(strict_types=1);

namespace Cbox\Tax\EuTaxData;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Loads the compiled `cboxdk/eu-tax-dataset` (schemaVersion 1) and answers two
 * questions about it: what a member state charged on a date, and which TEDB
 * heading a product class asks under.
 *
 * VERIFIED BEFORE IT IS BELIEVED. The published location is a branch head on a
 * third-party host, so one bad push would otherwise reach every deployment within
 * one cache TTL with nobody having released anything. The manifest carries a sha256
 * per section and the schemaVersion the files were built to; a section that does
 * not match is refused, and the caller denies rather than billing on it.
 *
 * A local path is trusted without a manifest. Pointing this at your own disk is a
 * deliberate act; pointing it at a URL that answers without a manifest is a fetch
 * that went somewhere unexpected.
 */
readonly class EuTaxDataset
{
    private const array SECTIONS = ['rates', 'class-map'];

    /**
     * The version these readers were written against. A bump means the publisher
     * changed something a reader relies on — refuse rather than guess which.
     */
    private const int SCHEMA_VERSION = 1;

    public function __construct(
        private Factory $http,
        private Cache $cache,
        private string $location,
        private int $ttl = 86400,
    ) {}

    /**
     * The rate window in force for a member state on a date.
     *
     * The dataset carries a dated series back to the start of the Commission's
     * records, so a supply in 2024 is priced with the rate that applied in 2024 —
     * which is the whole reason the history exists. Null when the country is absent
     * or nothing covers the date.
     *
     * @return array{standard: string, bands: array<array-key, mixed>, undecided: array<array-key, mixed>}|null
     */
    public function window(string $country, ?DateTimeImmutable $at = null): ?array
    {
        $section = $this->section('rates');
        $states = is_array($section['states'] ?? null) ? $section['states'] : null;
        $windows = is_array($states[$country] ?? null) ? $states[$country] : null;

        if ($windows === null) {
            return null;
        }

        $date = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');

        foreach ($windows as $window) {
            if (! is_array($window)) {
                continue;
            }

            $from = is_string($window['effectiveFrom'] ?? null) ? $window['effectiveFrom'] : null;
            $to = is_string($window['effectiveTo'] ?? null) ? $window['effectiveTo'] : null;

            if (($from === null || $from <= $date) && ($to === null || $date <= $to)) {
                $standard = $window['standard'] ?? null;

                if (! is_string($standard)) {
                    return null;
                }

                return [
                    'standard' => $standard,
                    'bands' => is_array($window['bands'] ?? null) ? $window['bands'] : [],
                    'undecided' => is_array($window['undecided'] ?? null) ? $window['undecided'] : [],
                ];
            }
        }

        // A date before the records begin, and the honest answer is nothing. The
        // oldest window carries `startsBeforeRecords`, so it covers everything from
        // the archive's start — anything earlier was never observed, and returning
        // the oldest rate would be inventing a boundary the publisher declined to.
        return null;
    }

    /**
     * The TEDB headings a product class asks under, most specific first.
     *
     * Order is data. France carries no `BOOKS` heading at all — printed books sit
     * under `LOAN_LIBRARIES` — because member states split Annex III point 6 at
     * different granularities, so a caller tries them in turn.
     *
     * An empty list is the answer for a class the EU reduces nowhere: electronics,
     * furniture, prewritten software. Those take the standard rate.
     *
     * @return list<string>
     */
    public function headingsFor(string $class): array
    {
        $section = $this->section('class-map');
        $classes = is_array($section['classes'] ?? null) ? $section['classes'] : [];
        $headings = $classes[$class] ?? null;

        if (! is_array($headings)) {
            return [];
        }

        return array_values(array_filter($headings, 'is_string'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function section(string $section): ?array
    {
        if (! in_array($section, self::SECTIONS, true)) {
            return null;
        }

        $key = 'cbox-tax:eu-dataset:'.substr(hash('sha256', $this->location), 0, 16).':'.$section;
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $payload = $this->fetch($section);

        if ($payload !== null) {
            $this->cache->put($key, $payload, $this->ttl);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(string $section): ?array
    {
        $raw = $this->read('by-section/'.$section.'.json');

        if ($raw === null || ! $this->verified($section, $raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Whether the bytes match what the publisher said they would be.
     *
     * Two things are checked and they fail differently. A schemaVersion the reader
     * does not know means the publisher changed something this code relies on, and
     * guessing which is worse than denying. A sha256 mismatch means the bytes are
     * not the ones the manifest describes — a partial write, a cache serving
     * something else, or a push nobody meant to make.
     *
     * A local location has no manifest requirement: reading your own disk is a
     * choice you made, and a remote read without one is not.
     */
    private function verified(string $section, string $raw): bool
    {
        if (! $this->isRemote()) {
            return true;
        }

        $manifest = $this->manifest();

        if ($manifest === null) {
            return false;
        }

        if (($manifest['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            return false;
        }

        // files.sections.<name>.sha256 — the sections are NESTED, and reading
        // files.<name> instead silently failed every remote verification while the
        // local path (trusted without a manifest) kept the tests green. The fixture
        // agreed because it had been hand-built to match the assumption rather than
        // the published bytes.
        $files = $manifest['files'] ?? null;
        $sections = is_array($files) ? ($files['sections'] ?? null) : null;
        $entry = is_array($sections) ? ($sections[$section] ?? null) : null;
        $expected = is_array($entry) ? ($entry['sha256'] ?? null) : null;

        return is_string($expected) && hash('sha256', $raw) === $expected;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function manifest(): ?array
    {
        $key = 'cbox-tax:eu-dataset:'.substr(hash('sha256', $this->location), 0, 16).':manifest';
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $raw = $this->read('manifest.json');
        $decoded = $raw === null ? null : json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        // Cached for a fraction of the section TTL. The manifest is what makes every
        // other read trustworthy, so holding a stale one for a day would keep
        // verifying against yesterday's answer after a correction was published.
        $this->cache->put($key, $decoded, max(60, (int) ($this->ttl / 12)));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function read(string $path): ?string
    {
        $target = rtrim($this->location, '/').'/'.$path;

        if (! $this->isRemote()) {
            $contents = is_file($target) ? file_get_contents($target) : false;

            return $contents === false ? null : $contents;
        }

        try {
            $response = $this->http->timeout(15)->get($target);

            return $response->successful() ? $response->body() : null;
        } catch (Throwable) {
            // Deny-by-default: an unreachable dataset is not a country with no VAT.
            return null;
        }
    }

    private function isRemote(): bool
    {
        return str_starts_with($this->location, 'http://') || str_starts_with($this->location, 'https://');
    }
}
