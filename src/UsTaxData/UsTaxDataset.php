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

    /** The only dataset schema this reader understands. See {@see verified()}. */
    private const int SCHEMA_VERSION = 4;

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
    public function stateRatePercent(string $state, ?DateTimeImmutable $at = null): ?string
    {
        $baseline = $this->currentBaseline($state, $at);

        if ($baseline === null || ($baseline['noSalesTax'] ?? null) === true) {
            return null;
        }

        return $this->fractionToPercent($baseline['stateRate'] ?? null);
    }

    /**
     * Whether the state levies no general sales tax (DE, MT, NH, OR), or NULL when
     * the baseline cannot be read.
     *
     * Nullable because the alternative answers wrongly in the unsafe direction: a
     * bare `false` on an unreachable dataset says "this state does tax", which is a
     * claim, not an absence of one. Every other accessor here refuses; so does this.
     */
    public function hasNoSalesTax(string $state): ?bool
    {
        $baseline = $this->currentBaseline($state);

        return $baseline === null ? null : ($baseline['noSalesTax'] ?? null) === true;
    }

    /**
     * Whether local (county/city/borough) authorities levy a sales tax in the
     * state, per the curated baseline.
     *
     * The load-bearing case is Alaska: no STATE sales tax, but boroughs and cities
     * levy their own (Juneau 5%, Wrangell 7%, with no statutory cap). Its baseline is
     * `stateRate: 0` with `noSalesTax: false` — a state share that is genuinely
     * zero, sitting under a local share that is not. A caller that resolves only
     * the state share there must refuse rather than report 0%.
     */
    public function hasLocalSalesTax(string $state): bool
    {
        $baseline = $this->currentBaseline($state);

        return $baseline !== null && ($baseline['localsExist'] ?? null) === true;
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
     * lists (schemaVersion 4), so pick the one covering the date — and only that.
     *
     * @return array<array-key, mixed>|null
     */
    private function currentBaseline(string $state, ?DateTimeImmutable $at = null): ?array
    {
        $entry = $this->stateEntry('baseline', $state);
        $windows = is_array($entry) && is_array($entry['baseline'] ?? null) ? $entry['baseline'] : null;

        return $windows === null ? null : $this->activeWindow($windows, $at);
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
     * The local authority code for a county NAME, for the states where the county
     * is the only local authority that can apply.
     *
     * The dataset codes local authorities as `US-FL:Alachua County` while a geocoder
     * hands back a plain `Alachua County`, so the join is on the name — and a name
     * join is exactly the kind that fails silently, so it is done in ONE place with
     * the failure made explicit.
     *
     * Matching is on a normalized key: case-folded, punctuation reduced to spaces
     * (`St. Johns` and `St Johns` are the same county) and the governing-unit suffix
     * dropped. That last part is what makes Philadelphia work — the dataset carries
     * it as a CITY named `Philadelphia`, because the city and the county are
     * coterminous, while a geocoder says `Philadelphia County`.
     *
     * Null on no match AND on an ambiguous one. Two authorities normalizing to the
     * same key means the dataset cannot say which the address is in, and picking
     * either would attach one authority's rate to the other's territory; the caller
     * falls back to the state share, which is honest about being partial.
     */
    public function localCodeForCounty(string $state, string $county): ?string
    {
        $wanted = self::countyKey($county);

        if ($wanted === '') {
            return null;
        }

        $rates = $this->stateEntry('rates', $state);
        $local = is_array($rates) && is_array($rates['local'] ?? null) ? $rates['local'] : null;

        if ($local === null) {
            return null;
        }

        $matches = [];

        foreach (array_keys($local) as $code) {
            if (! is_string($code)) {
                continue;
            }

            // `US-FL:Alachua County` — the authority name is what follows the state
            // prefix. A code without one is used whole rather than skipped.
            $name = str_contains($code, ':') ? substr($code, strpos($code, ':') + 1) : $code;

            if (self::countyKey($name) === $wanted) {
                $matches[] = $code;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * The normalized join key for a county name: case-folded, punctuation reduced to
     * single spaces, and the governing-unit suffix removed so `Philadelphia` and
     * `Philadelphia County` are the same key.
     *
     * `parish` and `borough` are stripped too. Neither Louisiana nor Alaska is
     * county-resolved today, but they are what those states call the same unit, and
     * a key that silently stopped matching if one were added later would be a
     * fallback to the state rate that nobody would notice.
     */
    private static function countyKey(string $name): string
    {
        $key = strtolower(trim($name));
        $key = (string) preg_replace('/[^a-z0-9]+/', ' ', $key);
        $key = trim((string) preg_replace('/\s+/', ' ', $key));

        return (string) preg_replace('/\s+(county|parish|borough)$/', '', $key);
    }

    /**
     * The taxability determination for a (state, dataset-category) pair, or null
     * when the dataset carries no rule for it (the caller then applies its own
     * default or denies).
     */
    public function taxability(string $state, string $category, ?DateTimeImmutable $at = null): ?TaxabilityDetermination
    {
        $rules = $this->stateEntry('taxability', $state);

        if (! is_array($rules)) {
            return null;
        }

        // Taxability is a dated-window list; a category may carry several windows.
        // Take the one in effect on the supply's date — the windows exist to carry
        // law that changed, and evaluating them all against today made every one of
        // them unreachable for a supply that was not made today.
        $matching = [];

        foreach ($rules as $rule) {
            if (is_array($rule) && ($rule['category'] ?? null) === $category) {
                $matching[] = $rule;
            }
        }

        $rule = $this->activeWindow($matching, $at);

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
     * the record whose [effectiveFrom, effectiveTo] covers the date, and ONLY that.
     * Null when the list is empty or nothing covers the date.
     *
     * @param  array<array-key, mixed>  $windows
     * @return array<array-key, mixed>|null
     */
    private function activeWindow(array $windows, ?DateTimeImmutable $at = null): ?array
    {
        $date = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');

        foreach ($windows as $window) {
            if (! is_array($window)) {
                continue;
            }

            $from = is_string($window['effectiveFrom'] ?? null) ? $window['effectiveFrom'] : null;
            $to = is_string($window['effectiveTo'] ?? null) ? $window['effectiveTo'] : null;

            if (($from === null || $from <= $date) && ($to === null || $date <= $to)) {
                return $window;
            }
        }

        // No window covers the date. There is deliberately NO fallback to the first
        // window in the list: the dated-window mechanism exists precisely to carry
        // pending and repealed law, and serving a window the dataset said does not
        // apply defeats it. A state whose only window starts in 2027 would have that
        // future threshold applied today; one whose window has ended would serve a
        // repealed rate indefinitely. Refusing sends the caller to its own
        // deny-by-default path, which every caller here already has.
        return null;
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
     * boundary index (`boundaries/US-XX.json`). This is the half of rooftop the rate
     * records cannot supply: they say what each authority charges, while the index
     * says which ones apply — inside Kansas City a county AND a city record apply,
     * inside Seattle only the city one does.
     *
     * The index is dictionary-encoded: a `sets` table of distinct authority
     * combinations, `zip` entries as `[from, to, setIndex]` scoped to one ZIP5, and
     * `ranges` as `[zipFrom, zipTo, from, to, setIndex]` for rows spanning several
     * ZIP5s (Indiana states its whole state that way, carrying no authorities at
     * all). Exact ZIP entries are tried first, so a specific assignment wins.
     *
     * An empty list is a real answer — the state rate alone applies there. A null is
     * "not carried", so the caller falls back to the state rate rather than assuming
     * none apply.
     *
     * @return list<string>|null
     */
    public function localJurisdictions(string $state, string $zip5, string $plus4): ?array
    {
        $index = $this->boundaries($state);

        if ($index === null) {
            return null;
        }

        $zips = is_array($index['zip'] ?? null) ? $index['zip'] : [];

        foreach (is_array($zips[$zip5] ?? null) ? $zips[$zip5] : [] as $entry) {
            if (! is_array($entry) || count($entry) < 3) {
                continue;
            }

            [$from, $to, $set] = [$entry[0], $entry[1], $entry[2]];

            if ($this->within($from, $to, $plus4)) {
                return $this->set($index, $set);
            }
        }

        foreach (is_array($index['ranges'] ?? null) ? $index['ranges'] : [] as $range) {
            if (! is_array($range) || count($range) < 5) {
                continue;
            }

            [$zipFrom, $zipTo, $from, $to, $set] = [$range[0], $range[1], $range[2], $range[3], $range[4]];

            if ($this->within($zipFrom, $zipTo, $zip5) && $this->within($from, $to, $plus4)) {
                return $this->set($index, $set);
            }
        }

        return null;
    }

    /** Whether a string key falls inside an inclusive, equal-width range. */
    private function within(mixed $from, mixed $to, string $value): bool
    {
        return is_string($from) && is_string($to) && $from <= $value && $value <= $to;
    }

    /**
     * Resolve a span's set index against the index's `sets` table.
     *
     * @param  array<array-key, mixed>  $index
     * @return list<string>
     */
    private function set(array $index, mixed $position): array
    {
        $sets = is_array($index['sets'] ?? null) ? $index['sets'] : [];
        $codes = is_int($position) && is_array($sets[$position] ?? null) ? $sets[$position] : [];

        $resolved = [];

        foreach ($codes as $code) {
            if (is_string($code) && $code !== '') {
                $resolved[] = $code;
            }
        }

        return $resolved;
    }

    /**
     * A state's boundary index, fetched lazily and cached like a section.
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

        // Boundary indexes are published GZIPPED — 5.4 MB across the 24 member
        // states instead of 20 MB, and they are rewritten every quarter, so the
        // uncompressed form would dominate the mirror's history. The plain file is
        // still read where a local build wrote one.
        $raw = $this->readCompressed('boundaries/'.$state.'.json.gz')
            ?? $this->read('boundaries/'.$state.'.json');

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! is_array($decoded['sets'] ?? null)) {
            return null;
        }

        /** @var array<array-key, mixed> $decoded */
        $this->cache->put($key, $decoded, $this->ttl);

        return $decoded;
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

        if (! $this->verified($section, $raw)) {
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
    /**
     * Read a gzipped part and inflate it. A body that is not valid gzip yields null
     * rather than a warning-laden empty string, so the caller falls through to the
     * plain file.
     */
    private function readCompressed(string $relative): ?string
    {
        $raw = $this->read($relative);

        if ($raw === null || $raw === '') {
            return null;
        }

        $inflated = @gzdecode($raw);

        return $inflated === false ? null : $inflated;
    }

    /**
     * Whether a fetched section is the one the publisher signed off.
     *
     * The ETL publishes a `manifest.json` carrying a sha256 per section and the
     * `schemaVersion` the files were built to, and its own docs tell consumers to
     * check both. Not checking them left two holes with no alarm on either:
     *
     *  - a schemaVersion bump that changed a unit — `stateRate` from a fraction to
     *    a percentage — would be multiplied by 100 again here and charge 725% tax
     *    at `Confidence::Authoritative`, reaching every deployment on the next
     *    cache expiry with nobody releasing anything;
     *  - the default location is a MUTABLE branch head on a third-party host, so
     *    one bad push lands everywhere within one TTL.
     *
     * Verification is REQUIRED over http(s) and optional for a local directory.
     * That is not laziness: over the network you did not choose the bytes, and on
     * your own disk you did. A local mirror without a manifest is a deliberate
     * choice; a remote one without a manifest is a fetch that went somewhere
     * unexpected.
     */
    private function verified(string $section, string $raw): bool
    {
        $manifest = $this->manifest();

        if ($manifest === null) {
            return ! $this->isRemote();
        }

        // A schema this reader was not written for cannot be read safely. The
        // fields it renamed or re-scaled are exactly the ones money depends on.
        if (($manifest['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            return false;
        }

        $files = $manifest['files'] ?? null;
        $sections = is_array($files) ? ($files['sections'] ?? null) : null;
        $entry = is_array($sections) ? ($sections[$section] ?? null) : null;
        $expected = is_array($entry) ? ($entry['sha256'] ?? null) : null;

        if (! is_string($expected)) {
            // The manifest exists but says nothing about this section. Remotely
            // that is a gap we cannot close; locally it is the operator's own file.
            return ! $this->isRemote();
        }

        return hash_equals($expected, hash('sha256', $raw));
    }

    /**
     * The publisher's manifest, cached alongside the sections. Null when there is
     * none, or it cannot be read or parsed.
     *
     * @return array<array-key, mixed>|null
     */
    private function manifest(): ?array
    {
        $key = 'cbox-tax:us-dataset:'.substr(hash('sha256', $this->location), 0, 16).':manifest';
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $raw = $this->read('manifest.json');

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        $this->cache->put($key, $decoded, $this->ttl);

        return $decoded;
    }

    private function isRemote(): bool
    {
        return str_starts_with($this->location, 'http://') || str_starts_with($this->location, 'https://');
    }

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
