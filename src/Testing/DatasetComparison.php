<?php

declare(strict_types=1);

namespace Cbox\Tax\Testing;

use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\Register\Store\ShardReader;
use DateTimeImmutable;
use Throwable;

/**
 * Two datasets, asked everything the engine can ask, and where they answer
 * differently.
 *
 * For proving that an implementation of {@see RegisterData} returns what another
 * returns for the same release — a database reader against the file store compiled
 * from the published sections, say. It walks every section, every shard, every
 * jurisdiction's rates and record, the rules and standard rates of each, the
 * vocabulary, every country's candidate codes, every US state's local claims and,
 * unless told not to, every boundary file.
 *
 * HOW THINGS ARE COMPARED. A list is compared in order, because order is part of the
 * answer: where a category has two candidate rates the resolver takes the first. A
 * map is compared key by key, and the order of its keys is not a difference. Scalars
 * are compared strictly: `"25"` and `25` differ, and so do `25` and `25.0`.
 *
 * WHAT IS NOT COMPARED, because it describes the store and not the release: the
 * manifest's `compiledAt`, `files` and `streets`. A street index one side was not
 * built with is listed under {@see skipped()}, not as a difference.
 *
 * Every difference is counted; the first {@see $keep} are kept in full, and
 * {@see patterns()} groups all of them by where they are with the codes and indexes
 * taken out (`rates[*].provenance.snapshot`), which is the shape a cause has.
 */
class DatasetComparison
{
    /** Manifest fields that identify the release, as opposed to describing a store. */
    private const array RELEASE_FIELDS = ['version', 'contentHash', 'schemaVersion', 'publishedAt', 'regions', 'states'];

    private const array SECTIONS = ['meta', 'rules', 'standard-rates', 'coverage', 'us-local', 'facts'];

    private const array BOUNDARY_DOCUMENTS = ['.zip.head.json', '.geo.boxes.json', '.overlay.json'];

    private const array BOUNDARY_SHARDS = ['.zip', '.geo'];

    /** @var list<DatasetDifference> */
    private array $kept = [];

    private int $total = 0;

    /** @var array<string, int> */
    private array $patterns = [];

    /** @var array<string, int> */
    private array $compared = [];

    /** @var list<string> */
    private array $skipped = [];

    private function __construct(private readonly int $keep) {}

    /**
     * @param  bool  $boundaries  also walk every compiled boundary file of every US state
     * @param  int  $keep  how many differences to keep in full; all are counted
     */
    public static function between(RegisterData $left, RegisterData $right, bool $boundaries = true, int $keep = 200): self
    {
        $comparison = new self($keep);
        $comparison->walk($left, $right, $boundaries);

        return $comparison;
    }

    public function identical(): bool
    {
        return $this->total === 0;
    }

    /** How many answers differ. */
    public function count(): int
    {
        return $this->total;
    }

    /**
     * The first differences found, in full.
     *
     * @return list<DatasetDifference>
     */
    public function differences(): array
    {
        return $this->kept;
    }

    /**
     * Every difference, grouped by where it is with codes and indexes removed, most
     * frequent first.
     *
     * @return array<string, int>
     */
    public function patterns(): array
    {
        $patterns = $this->patterns;
        arsort($patterns);

        return $patterns;
    }

    /**
     * How many things of each kind were compared: `sections`, `shards`, `codes`,
     * `rates`, `jurisdictions`, `rules`, `standardRates`, `categories`, `countries`,
     * `states`, `facts`, `boundaryFiles`, `boundaryRecords`.
     *
     * @return array<string, int>
     */
    public function compared(): array
    {
        return $this->compared;
    }

    /**
     * What was deliberately not compared, and why.
     *
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** A few lines a person can read: what was walked and what differs. */
    public function summary(): string
    {
        $lines = [];
        $walked = [];

        foreach ($this->compared as $what => $count) {
            $walked[] = number_format($count).' '.$what;
        }

        $lines[] = 'Compared '.implode(', ', $walked).'.';
        $lines[] = $this->total === 0 ? 'No differences.' : number_format($this->total).' difference(s):';

        foreach ($this->patterns() as $pattern => $count) {
            $lines[] = sprintf('  %7s  %s', number_format($count), $pattern);
        }

        foreach ($this->skipped as $note) {
            $lines[] = 'Skipped: '.$note;
        }

        return implode("\n", $lines);
    }

    private function walk(RegisterData $left, RegisterData $right, bool $boundaries): void
    {
        $this->same('release.version', $left->version(), $right->version());

        if ($left->version() === null || $right->version() === null) {
            return;
        }

        $leftManifest = $left->manifest();
        $rightManifest = $right->manifest();

        foreach (self::RELEASE_FIELDS as $field) {
            $this->same('manifest.'.$field, $leftManifest[$field] ?? null, $rightManifest[$field] ?? null);
        }

        foreach (self::SECTIONS as $section) {
            $this->tally('sections');
            $this->same($section, $left->document($section), $right->document($section));
        }

        $shards = $this->union($left->shards(), $right->shards());
        $this->same('shards', $this->sorted($left->shards()), $this->sorted($right->shards()));

        $states = [];

        foreach ($shards as $shard) {
            $this->tally('shards');

            if (str_starts_with($shard, 'us/') && $shard !== 'us/_US') {
                $states[] = substr($shard, 3);
            }

            $this->same('shard['.$shard.'].codes', $this->sorted($left->codesIn($shard)), $this->sorted($right->codesIn($shard)));
            $this->same('shard['.$shard.'].names', $left->namesIn($shard), $right->namesIn($shard));

            foreach ($this->union($left->codesIn($shard), $right->codesIn($shard)) as $code) {
                $this->code($left, $right, $code);
            }
        }

        $this->vocabulary($left, $right);
        $this->countries($left, $right);

        $states = $this->union($states, array_map(strval(...), array_keys(Shape::map($left->document('us-local')['resolution'] ?? null))));

        foreach ($states as $state) {
            $this->state($left, $right, $state, $boundaries);
        }
    }

    private function code(RegisterData $left, RegisterData $right, string $code): void
    {
        $this->tally('codes');

        $leftRates = $left->ratesFor($code);
        $this->tally('rates', count($leftRates));
        $this->same('rates['.$code.']', $leftRates, $right->ratesFor($code));

        $leftRecord = $left->jurisdiction($code);
        $this->tally('jurisdictions', $leftRecord === null ? 0 : 1);
        $this->same('jurisdiction['.$code.']', $leftRecord, $right->jurisdiction($code));

        $leftRules = $left->rulesFor($code);
        $this->tally('rules', count($leftRules));
        $this->same('rulesFor['.$code.']', $leftRules, $right->rulesFor($code));

        $leftStandard = $left->standardRates($code);
        $this->tally('standardRates', count($leftStandard));
        $this->same('standardRates['.$code.']', $leftStandard, $right->standardRates($code));

        $this->same('carries['.$code.']', $left->carries($code), $right->carries($code));
    }

    private function vocabulary(RegisterData $left, RegisterData $right): void
    {
        $leftCategories = $left->categories();
        $this->tally('categories', count($leftCategories));
        // In order: the vocabulary is published in an order and read back in it.
        $this->same('categories.keys', array_keys($leftCategories), array_keys($right->categories()));
        $this->same('categories', $leftCategories, $right->categories());

        $names = [];

        foreach ([$left, $right] as $dataset) {
            foreach (Shape::records($dataset->document('facts')['facts'] ?? null) as $fact) {
                $name = Shape::text($fact['fact'] ?? null);

                if ($name !== null) {
                    $names[$name] = true;
                }
            }
        }

        foreach (array_keys($names) as $name) {
            $this->tally('facts');
            $this->same('fact['.$name.']', $this->exported($left->fact($name)), $this->exported($right->fact($name)));
        }
    }

    private function countries(RegisterData $left, RegisterData $right): void
    {
        $countries = [];

        foreach ([$left, $right] as $dataset) {
            foreach (Shape::records($dataset->document('meta')['regimes'] ?? null) as $regime) {
                foreach (Shape::records($regime['members'] ?? null) as $member) {
                    $code = Shape::text($member['code'] ?? null);

                    if ($code !== null && str_contains($code, ':')) {
                        $countries[substr($code, (int) strrpos($code, ':') + 1)] = true;
                    }
                }
            }
        }

        // Membership is over time: today, a day before Brexit took effect, and a
        // day before the oldest accession the register dates.
        $dates = [new DateTimeImmutable('today'), new DateTimeImmutable('2019-06-01'), new DateTimeImmutable('1994-06-01')];

        foreach (array_keys($countries) as $iso) {
            $this->tally('countries');

            foreach ($dates as $date) {
                $this->same(
                    'codesForCountry['.$iso.'@'.$date->format('Y-m-d').']',
                    $left->codesForCountry((string) $iso, $date),
                    $right->codesForCountry((string) $iso, $date),
                );
            }
        }
    }

    private function state(RegisterData $left, RegisterData $right, string $state, bool $boundaries): void
    {
        $this->tally('states');

        $today = new DateTimeImmutable('today');

        $this->same('usLocalResolution['.$state.']', $left->usLocalResolution($state), $right->usLocalResolution($state));
        $this->same('usLocalUnpriced['.$state.']', $left->usLocalUnpriced($state), $right->usLocalUnpriced($state));
        $this->same('usLocalAbsenceHolds['.$state.']', $left->usLocalAbsenceHolds($state, $today), $right->usLocalAbsenceHolds($state, $today));
        $this->same('publishesGeometryFor['.$state.']', $left->publishesGeometryFor($state), $right->publishesGeometryFor($state));
        $this->same('publishesOverlayFor['.$state.']', $left->publishesOverlayFor($state), $right->publishesOverlayFor($state));

        if (! $boundaries) {
            return;
        }

        foreach (self::BOUNDARY_DOCUMENTS as $suffix) {
            $this->boundaryDocument($left, $right, $state.$suffix);
        }

        foreach (self::BOUNDARY_SHARDS as $suffix) {
            $this->boundaryShard($left, $right, $state.$suffix);
        }

        $this->boundaryBytes($left, $right, $state.'.geo.json');

        // The street index is asked for by name when a store is built (228 MB across
        // fifteen states), so one side holding it and the other not is a difference
        // between two stores, not between two readings of a release.
        $leftStreets = is_file($left->boundaryPath($state.'.street.idx'));
        $rightStreets = is_file($right->boundaryPath($state.'.street.idx'));

        if ($leftStreets !== $rightStreets) {
            $this->skipped[] = sprintf('street index of %s: only the %s dataset holds one', $state, $leftStreets ? 'left' : 'right');

            return;
        }

        if ($leftStreets) {
            $this->boundaryDocument($left, $right, $state.'.street.sets.json');
            $this->boundaryShard($left, $right, $state.'.street');
        }
    }

    private function boundaryDocument(RegisterData $left, RegisterData $right, string $file): void
    {
        $a = $this->json($left->boundaryPath($file));
        $b = $this->json($right->boundaryPath($file));

        if ($a === null && $b === null) {
            return;
        }

        $this->tally('boundaryFiles');
        $this->same('boundary['.$file.']', $a, $b);
    }

    private function boundaryShard(RegisterData $left, RegisterData $right, string $file): void
    {
        $a = new ShardReader($left->boundaryPath($file));
        $b = new ShardReader($right->boundaryPath($file));

        if (! $a->exists() && ! $b->exists()) {
            return;
        }

        $this->tally('boundaryFiles');
        $this->same('boundary['.$file.'].present', $a->exists(), $b->exists());

        if (! $a->exists() || ! $b->exists()) {
            return;
        }

        $this->same('boundary['.$file.'].keys', $this->sorted($a->keys()), $this->sorted($b->keys()));

        foreach ($a->keys() as $key) {
            $key = (string) $key;

            if (! $b->has($key)) {
                continue;
            }

            $this->tally('boundaryRecords');
            $this->same('boundary['.$file.']['.$key.']', $a->read($key), $b->read($key));
        }
    }

    /** A file too large to decode in a test, compared by its bytes. */
    private function boundaryBytes(RegisterData $left, RegisterData $right, string $file): void
    {
        $a = $left->boundaryPath($file);
        $b = $right->boundaryPath($file);

        if (! is_file($a) && ! is_file($b)) {
            return;
        }

        $this->tally('boundaryFiles');
        $this->same(
            'boundary['.$file.'].sha256',
            is_file($a) ? hash_file('sha256', $a) : null,
            is_file($b) ? hash_file('sha256', $b) : null,
        );
    }

    /**
     * Record every place two values differ, descending into arrays.
     */
    private function same(string $where, mixed $a, mixed $b): void
    {
        if ($a === $b) {
            return;
        }

        if (is_array($a) && is_array($b)) {
            if (array_is_list($a) && array_is_list($b)) {
                if (count($a) !== count($b)) {
                    $this->differs($where.'.count', count($a), count($b));
                }

                foreach ($a as $index => $value) {
                    if (array_key_exists($index, $b)) {
                        $this->same($where.'['.$index.']', $value, $b[$index]);
                    }
                }

                return;
            }

            foreach ($this->union(array_map(strval(...), array_keys($a)), array_map(strval(...), array_keys($b))) as $key) {
                $inA = array_key_exists($key, $a);
                $inB = array_key_exists($key, $b);

                if ($inA && $inB) {
                    $this->same($where.'.'.$key, $a[$key], $b[$key]);

                    continue;
                }

                $this->differs($where.'.'.$key, $inA ? $a[$key] : '(absent)', $inB ? $b[$key] : '(absent)');
            }

            return;
        }

        $this->differs($where, $a, $b);
    }

    private function differs(string $where, mixed $a, mixed $b): void
    {
        $this->total++;

        // The place with what varies taken out: `rates[eu:DK][3].percentage` and
        // `rates[us:TX][0].percentage` are one cause, `rates[*][*].percentage`.
        $pattern = (string) preg_replace('/\[[^\]]*\]/', '[*]', $where);
        $this->patterns[$pattern] = ($this->patterns[$pattern] ?? 0) + 1;

        if (count($this->kept) < $this->keep) {
            $this->kept[] = new DatasetDifference($where, $this->shown($a), $this->shown($b));
        }
    }

    private function shown(mixed $value): string
    {
        try {
            $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (Throwable) {
            $text = get_debug_type($value);
        }

        return mb_strlen($text) > 240 ? mb_substr($text, 0, 240).'…' : $text;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exported(?object $value): ?array
    {
        return $value === null ? null : Shape::map(get_object_vars($value));
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function json(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function tally(string $what, int $by = 1): void
    {
        $this->compared[$what] = ($this->compared[$what] ?? 0) + $by;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_map(Shape::scalar(...), array_values($values));
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param  array<array-key, mixed>  $a
     * @param  array<array-key, mixed>  $b
     * @return list<string>
     */
    private function union(array $a, array $b): array
    {
        $seen = [];

        foreach ([...array_values($a), ...array_values($b)] as $value) {
            $seen[Shape::scalar($value)] = true;
        }

        return array_map(strval(...), array_keys($seen));
    }
}
