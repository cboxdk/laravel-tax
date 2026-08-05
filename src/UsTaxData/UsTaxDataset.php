<?php

declare(strict_types=1);

namespace Cbox\Tax\UsTaxData;

use Brick\Math\BigDecimal;
use Cbox\Tax\Enums\RateBasis;
use Cbox\Tax\Enums\TaxabilityTreatment;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Loads the compiled us-tax-data dataset (schemaVersion 4) and exposes typed,
 * per-state access to its four planes: state rates, product taxability, economic
 * nexus, and intrastate sourcing. It reads the SPLIT `by-section` files, not the
 * multi-megabyte full artifact, so the common (state-level) path only ever
 * transfers the small baseline/taxability/nexus/sourcing sections; the bulky
 * `rates` section (every local record) is fetched lazily and only when a rooftop
 * locality is actually resolved.
 *
 * The location is CONFIG-DRIVEN (`tax.us_tax_data.location`): an `http(s)://` base
 * URL (the public dataset mirror) or a local directory, under which the files live
 * at `by-section/<section>.json`. A URL source is cached (per section) in the
 * Laravel cache; every transport/read/parse failure yields a null/empty result so
 * a consumer denies rather than guessing — the deny-by-default contract the rest
 * of the engine holds.
 *
 * @phpstan-type StatesMap array<array-key, mixed>
 */
readonly class UsTaxDataset
{
    /** Sections this loader reads, each a `by-section/<name>.json` file. */
    private const array SECTIONS = ['baseline', 'taxability', 'nexus', 'sourcing', 'rates'];

    public function __construct(
        private Factory $http,
        private Cache $cache,
        private string $location,
        private int $ttl = 86400,
    ) {}

    /**
     * The applicable state-level rate as a percentage string (e.g. "7.25"), from
     * the curated baseline. Null when the state has no sales tax, no baseline, or
     * the section is unavailable — the caller then denies.
     */
    public function stateRatePercent(string $state): ?string
    {
        $baseline = $this->currentBaseline($state);

        if ($baseline === null || ($baseline['noSalesTax'] ?? null) === true) {
            return null;
        }

        return $this->fractionToPercent($baseline['stateRate'] ?? null);
    }

    /** Whether the state levies no general sales tax (DE, MT, NH, OR). */
    public function hasNoSalesTax(string $state): bool
    {
        $baseline = $this->currentBaseline($state);

        return $baseline !== null && ($baseline['noSalesTax'] ?? null) === true;
    }

    /**
     * The curated caveat on a state's BASELINE (the "see baseline note" in the dataset's
     * coverage matrix) — e.g. that a no-sales-tax state still levies a gross-receipts tax, or
     * that the statewide rate already folds in a mandatory local share. Reads the baseline
     * window in effect now. Null when the state carries no baseline note.
     */
    public function baselineNote(string $state): ?string
    {
        $baseline = $this->currentBaseline($state);
        $note = $baseline === null ? null : ($baseline['note'] ?? null);

        return is_string($note) && $note !== '' ? $note : null;
    }

    /**
     * The baseline window in effect now — the curated planes are dated-window
     * lists (schemaVersion 4), so pick the one covering today.
     *
     * @return array<array-key, mixed>|null
     */
    private function currentBaseline(string $state): ?array
    {
        $entry = $this->stateEntry('baseline', $state);
        $windows = is_array($entry) && is_array($entry['baseline'] ?? null) ? $entry['baseline'] : null;

        return $windows === null ? null : $this->activeWindow($windows);
    }

    /**
     * The rate basis for a state's local records — component (sum) or combined
     * (each record is already all-in). Null when the state carries no local rates.
     */
    public function rateBasis(string $state): ?RateBasis
    {
        $rates = $this->stateEntry('rates', $state);
        $basis = is_array($rates) ? ($rates['rateBasis'] ?? null) : null;

        return is_string($basis) ? RateBasis::tryFrom($basis) : null;
    }

    /**
     * The curated caveat on a state's RATE records (the "see state note" in the dataset's
     * coverage matrix) — what the records include, what is not modeled, or a point-in-time
     * snapshot warning. Reads the lazily-loaded `rates` section. Null when the state carries
     * no rate note.
     */
    public function rateNote(string $state): ?string
    {
        $rates = $this->stateEntry('rates', $state);
        $note = is_array($rates) ? ($rates['note'] ?? null) : null;

        return is_string($note) && $note !== '' ? $note : null;
    }

    /**
     * The local rate records for a rooftop locality code, each a decoded rate
     * record (`generalRate`, `level`, effective window…). Empty when the state or
     * code is not carried. Reads the lazily-loaded `rates` section.
     *
     * @return list<array<array-key, mixed>>
     */
    public function localRateRecords(string $state, string $code): array
    {
        $rates = $this->stateEntry('rates', $state);
        $local = is_array($rates) && is_array($rates['local'] ?? null) ? $rates['local'] : null;

        if ($local === null || ! is_array($local[$code] ?? null)) {
            return [];
        }

        $records = [];

        foreach ($local[$code] as $record) {
            if (is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * The taxability determination for a (state, dataset-category) pair, or null
     * when the dataset carries no rule for it (the caller then applies its own
     * default or denies).
     */
    public function taxability(string $state, string $category): ?TaxabilityDetermination
    {
        $rules = $this->stateEntry('taxability', $state);

        if (! is_array($rules)) {
            return null;
        }

        // Taxability is a dated-window list; a category may carry several windows.
        // Take the window for this category in effect now.
        $matching = [];

        foreach ($rules as $rule) {
            if (is_array($rule) && ($rule['category'] ?? null) === $category) {
                $matching[] = $rule;
            }
        }

        $rule = $this->activeWindow($matching);

        if ($rule === null) {
            return null;
        }

        $treatmentValue = $rule['treatment'] ?? null;
        $treatment = is_string($treatmentValue) ? TaxabilityTreatment::tryFrom($treatmentValue) : null;

        if ($treatment === null) {
            return null;
        }

        $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : null;

        return new TaxabilityDetermination($treatment, $treatment->isTaxable(), $conditions);
    }

    /**
     * A state's economic-nexus figures, or null when none is carried.
     *
     * @return array{salesUsd: int, transactions: int|null, combinator: string}|null
     */
    public function nexus(string $state): ?array
    {
        $windows = $this->stateEntry('nexus', $state);
        $nexus = is_array($windows) ? $this->activeWindow($windows) : null;

        if ($nexus === null) {
            return null;
        }

        $sales = $nexus['salesUsd'] ?? null;
        $combinator = $nexus['combinator'] ?? null;

        if (! is_int($sales) || ! is_string($combinator)) {
            return null;
        }

        $transactions = $nexus['transactions'] ?? null;

        return [
            'salesUsd' => $sales,
            'transactions' => is_int($transactions) ? $transactions : null,
            'combinator' => $combinator,
        ];
    }

    /**
     * A state's intrastate sourcing rule, or null when none is carried.
     *
     * @return array{mode: string, note: string|null}|null
     */
    public function sourcing(string $state): ?array
    {
        $windows = $this->stateEntry('sourcing', $state);
        $sourcing = is_array($windows) ? $this->activeWindow($windows) : null;

        if ($sourcing === null) {
            return null;
        }

        $mode = $sourcing['mode'] ?? null;

        if (! is_string($mode)) {
            return null;
        }

        $note = $sourcing['note'] ?? null;

        return ['mode' => $mode, 'note' => is_string($note) ? $note : null];
    }

    /**
     * The window in effect on `$at` (default: today) from a dated-window list —
     * the record whose [effectiveFrom, effectiveTo] covers the date, else the
     * first. Null for an empty list.
     *
     * @param  array<array-key, mixed>  $windows
     * @return array<array-key, mixed>|null
     */
    private function activeWindow(array $windows, ?DateTimeImmutable $at = null): ?array
    {
        $date = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $fallback = null;

        foreach ($windows as $window) {
            if (! is_array($window)) {
                continue;
            }

            $fallback ??= $window;

            $from = is_string($window['effectiveFrom'] ?? null) ? $window['effectiveFrom'] : null;
            $to = is_string($window['effectiveTo'] ?? null) ? $window['effectiveTo'] : null;

            if (($from === null || $from <= $date) && ($to === null || $date <= $to)) {
                return $window;
            }
        }

        return $fallback;
    }

    /**
     * The per-state value carried by a section's `states` map, or null.
     */
    private function stateEntry(string $section, string $state): mixed
    {
        $states = $this->section($section);

        return is_array($states) ? ($states[$state] ?? null) : null;
    }

    /**
     * The local taxing jurisdictions that apply at a ZIP+4, from the state's
     * boundary index (`boundaries/US-XX.json`). This is the half of rooftop the
     * rate records cannot supply: they say what each authority charges, while the
     * index says which ones apply — inside Kansas City a county AND a city record
     * apply, inside Seattle only the city one does.
     *
     * Returns the codes of the NARROWEST span covering the add-on, which the index
     * orders first. An empty list is a real answer where a ZIP resolves to no local
     * authority; a MISSING one (no index, no ZIP, no covering span) is a null, so
     * the caller falls back to the state rate rather than assuming none apply.
     *
     * @return list<string>|null
     */
    public function localJurisdictions(string $state, string $zip5, string $plus4): ?array
    {
        $index = $this->boundaries($state);
        $entries = is_array($index) && is_array($index[$zip5] ?? null) ? $index[$zip5] : null;

        if ($entries === null) {
            return null;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $from = is_string($entry['from'] ?? null) ? $entry['from'] : null;
            $to = is_string($entry['to'] ?? null) ? $entry['to'] : null;

            if ($from === null || $to === null || $from > $plus4 || $plus4 > $to) {
                continue;
            }

            $codes = [];

            foreach (is_array($entry['codes'] ?? null) ? $entry['codes'] : [] as $code) {
                if (is_string($code) && $code !== '') {
                    $codes[] = $code;
                }
            }

            return $codes;
        }

        return null;
    }

    /**
     * A state's ZIP-keyed boundary index, fetched lazily and cached like a section.
     * It is per state deliberately — an index is ~0.5–3.5 MB, which is fine for the
     * one state a supply resolved to and wasteful for all of them.
     *
     * @return array<array-key, mixed>|null
     */
    private function boundaries(string $state): ?array
    {
        $key = 'cbox-tax:us-dataset:'.substr(hash('sha256', $this->location), 0, 16).':boundaries:'.$state;

        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $raw = $this->read('boundaries/'.$state.'.json');

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! is_array($decoded['zip'] ?? null)) {
            return null;
        }

        /** @var array<array-key, mixed> $zip */
        $zip = $decoded['zip'];
        $this->cache->put($key, $zip, $this->ttl);

        return $zip;
    }

    /**
     * Load a section's `states` map — from the per-section cache, else fetched
     * from the configured location and cached. Returns null on any failure.
     *
     * @return StatesMap|null
     */
    private function section(string $section): ?array
    {
        if (! in_array($section, self::SECTIONS, true)) {
            return null;
        }

        $key = 'cbox-tax:us-dataset:'.substr(hash('sha256', $this->location), 0, 16).':'.$section;

        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $states = $this->fetchStates($section);

        if ($states !== null) {
            $this->cache->put($key, $states, $this->ttl);
        }

        return $states;
    }

    /**
     * @return StatesMap|null
     */
    private function fetchStates(string $section): ?array
    {
        $raw = $this->read('by-section/'.$section.'.json');

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! is_array($decoded['states'] ?? null)) {
            return null;
        }

        /** @var StatesMap */
        return $decoded['states'];
    }

    /**
     * Read a relative path under the configured location — an HTTP GET for a URL
     * base, a filesystem read for a local directory. Any failure returns null.
     */
    private function read(string $relative): ?string
    {
        $base = rtrim($this->location, '/');

        if (str_starts_with($this->location, 'http://') || str_starts_with($this->location, 'https://')) {
            try {
                $response = $this->http->acceptJson()->get($base.'/'.$relative);
            } catch (Throwable) {
                return null;
            }

            return $response->successful() ? $response->body() : null;
        }

        $path = $base.'/'.$relative;

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        return $raw === false ? null : $raw;
    }

    /**
     * Convert a dataset fraction (0.0725) to a percentage string ("7.25") for
     * {@see TaxRate}. Null-safe and numeric-guarded.
     */
    private function fractionToPercent(mixed $fraction): ?string
    {
        if (is_int($fraction) || is_float($fraction)) {
            $fraction = (string) $fraction;
        }

        if (! is_string($fraction) || ! is_numeric($fraction)) {
            return null;
        }

        return self::normalize((string) BigDecimal::of($fraction)->multipliedBy(100));
    }

    /**
     * Drop trailing zeros from a decimal string ("7.2500" -> "7.25", "4.0000" -> "4")
     * without mangling an integer string ("10" stays "10").
     */
    public static function normalize(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
