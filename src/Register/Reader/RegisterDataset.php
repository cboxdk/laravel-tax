<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

use Cbox\Tax\Exceptions\DatasetNotInstalled;
use Cbox\Tax\Exceptions\UnknownCategory;
use Cbox\Tax\Register\Store\ShardKey;
use Cbox\Tax\Register\Store\ShardReader;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use DateTimeImmutable;

/**
 * The typed way into a compiled store: what a jurisdiction charges, what rules scope
 * it, and what the register says about its own coverage.
 *
 * It reads local files and makes no network call. Everything it opens is opened
 * lazily and kept for the life of the object — a request that prices one line
 * usually prices the next one in the same jurisdiction, and the shard readers hold
 * an index, not records.
 *
 * The live version is resolved ONCE per instance. A sync that lands mid-request must
 * not move the answer under a half-priced invoice: two lines of one order have to be
 * priced by the same register, or the totals do not reconcile with either.
 */
final class RegisterDataset
{
    private ?string $version = null;

    private bool $resolved = false;

    private bool $schemaChecked = false;

    /** @var array<string, ShardReader> */
    private array $rates = [];

    /** @var array<string, ShardReader> */
    private array $jurisdictions = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $documents = null;

    /** @var array<string, list<array<string, mixed>>>|null */
    private ?array $rulesByJurisdiction = null;

    public function __construct(
        private readonly StoreLayout $layout,
        private readonly StorePointer $pointer,
        private readonly ?string $pinnedVersion = null,
    ) {}

    /** Where the store lives, for a refusal that can name it. */
    public function storeRoot(): string
    {
        return $this->layout->root();
    }

    public function isInstalled(): bool
    {
        return $this->version() !== null;
    }

    /** The live version, pinned for the life of this instance. */
    public function version(): ?string
    {
        if (! $this->resolved) {
            $this->version = $this->pinnedVersion === null
                ? $this->pointer->current()
                : (is_file($this->layout->manifest($this->pinnedVersion)) ? $this->pinnedVersion : null);
            $this->resolved = true;
        }

        return $this->version;
    }

    /**
     * @throws DatasetNotInstalled
     */
    public function requireVersion(): string
    {
        $version = $this->version();

        if ($version === null) {
            throw new DatasetNotInstalled($this->layout->root(), $this->pinnedVersion);
        }

        // A store can be installed by a newer worker and then opened by an older
        // one. Checking only at sync time does not protect offline pricing.
        if (! $this->schemaChecked) {
            RegisterCompatibility::schema($this->readDocument('manifest', $version)['schemaVersion'] ?? null, $version);
            $this->schemaChecked = true;
        }

        return $version;
    }

    /**
     * Every rate record the register holds for a jurisdiction, unfiltered.
     *
     * Dating, category and taxType are the resolver's job, not this one's — a reader
     * that filtered would have to decide what "current" means, and the register is
     * explicit that the test is containment rather than a null end date.
     *
     * @return list<array<string, mixed>>
     */
    public function ratesFor(string $jurisdiction): array
    {
        $shard = ShardKey::of($jurisdiction);

        $this->rates[$shard] ??= new ShardReader(
            $this->layout->file($this->requireVersion(), 'rates/'.$shard),
        );

        return $this->rates[$shard]->read($jurisdiction);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function jurisdiction(string $code): ?array
    {
        $shard = ShardKey::of($code);

        $this->jurisdictions[$shard] ??= new ShardReader(
            $this->layout->file($this->requireVersion(), 'jurisdictions/'.$shard),
        );

        return $this->jurisdictions[$shard]->read($code)[0] ?? null;
    }

    /**
     * Every jurisdiction in a shard, as code => published name.
     *
     * Only useful where a shard is small and a NAME is the key a caller holds —
     * Florida's 67 counties, Virginia's 39 independent localities, Pennsylvania's
     * two, Hawaii's four. None of those states files a boundary artifact, so a name
     * is the only handle there is.
     *
     * @return array<string, string>
     */
    public function namesIn(string $shard): array
    {
        $reader = $this->jurisdictions[$shard] ??= new ShardReader(
            $this->layout->file($this->requireVersion(), 'jurisdictions/'.$shard),
        );

        $names = [];

        foreach ($reader->keys() as $code) {
            $name = Shape::text($reader->read($code)[0]['name'] ?? null);

            if ($name !== null) {
                $names[$code] = $name;
            }
        }

        return $names;
    }

    /**
     * Whether the store actually carries this regime, as opposed to carrying it and
     * finding nothing.
     *
     * The distinction is the whole reason per-region and per-state opt-in is safe. A
     * store compiled with `--region=eu` holds no answer for Japan, and answering
     * "no tax in Japan" would be a fabrication; the manifest says which regimes were
     * compiled, so the engine can refuse with a remedy instead.
     */
    public function carries(string $jurisdiction): bool
    {
        $manifest = $this->document('manifest');
        $regions = $manifest['regions'] ?? null;
        $regime = ShardKey::regime($jurisdiction);

        if (is_array($regions) && ! in_array($regime, $regions, true)) {
            return false;
        }

        if ($regime !== 'us') {
            return true;
        }

        $states = $manifest['states'] ?? null;
        $parts = explode(':', $jurisdiction);

        return ! is_array($states) || ($parts[1] ?? null) === null || in_array($parts[1], $states, true);
    }

    /**
     * Rules of one kind that apply to a jurisdiction, in publication order.
     *
     * @return list<array<string, mixed>>
     */
    public function rulesFor(string $jurisdiction, ?string $kind = null): array
    {
        if ($this->rulesByJurisdiction === null) {
            $index = [];

            foreach (Shape::records($this->document('rules')['rules'] ?? null) as $rule) {
                $code = Shape::text($rule['jurisdiction'] ?? null);

                if ($code !== null) {
                    $index[$code][] = $rule;
                }
            }

            $this->rulesByJurisdiction = $index;
        }

        $rules = $this->rulesByJurisdiction[$jurisdiction] ?? [];

        if ($kind !== null) {
            $rules = array_values(array_filter($rules, static fn (array $rule): bool => ($rule['kind'] ?? null) === $kind));
        }

        foreach ($rules as $rule) {
            RegisterCompatibility::applicable($rule);
        }

        return $rules;
    }

    /**
     * Rules whose published window contains the requested calendar date.
     * End dates are inclusive. A capture floor does not establish earlier coverage.
     *
     * @return list<array<string, mixed>>
     */
    public function rulesOn(string $jurisdiction, string $kind, DateTimeImmutable $at): array
    {
        $on = $at->format('Y-m-d');

        return array_values(array_filter($this->rulesFor($jurisdiction, $kind), static function (array $rule) use ($on): bool {
            $effective = Shape::map($rule['effective'] ?? null);
            $from = Shape::text($effective['from'] ?? null);
            $until = Shape::text($effective['until'] ?? null);

            return ($from === null || $from <= $on) && ($until === null || $until >= $on);
        }));
    }

    /**
     * The standard-rate backbone: every jurisdiction that sets one, worldwide.
     *
     * @return list<array<string, mixed>>
     */
    public function standardRates(string $jurisdiction): array
    {
        $rows = [];

        foreach (Shape::records($this->document('standard-rates')['standardRates'] ?? null) as $rate) {
            if (($rate['jurisdiction'] ?? null) === $jurisdiction) {
                $rows[] = $rate;
            }
        }

        return $rows;
    }

    /**
     * The category vocabulary, keyed by its own key, so a resolver can walk `parent`
     * upward without a second lookup.
     *
     * @return array<string, array<string, mixed>>
     */
    public function categories(): array
    {
        $byKey = [];

        foreach (Shape::records($this->document('meta')['categories'] ?? null) as $category) {
            $key = Shape::text($category['key'] ?? null);

            if ($key !== null) {
                $byKey[$key] = $category;
            }
        }

        return $byKey;
    }

    /**
     * Refuse a category key this release does not publish, naming what it does
     * publish nearby — the same two leading segments — so a typo is a one-look fix.
     */
    public function assertCategoryPublished(string $key): void
    {
        $vocabulary = $this->categories();

        if (isset($vocabulary[$key])) {
            return;
        }

        $stem = implode('.', array_slice(explode('.', $key), 0, 2));
        $nearby = array_values(array_filter(array_keys($vocabulary), static fn (string $k): bool => str_starts_with($k, $stem)));

        if ($nearby === []) {
            $root = explode('.', $key)[0];
            $nearby = array_values(array_filter(array_keys($vocabulary), static fn (string $k): bool => str_starts_with($k, $root.'.')));
        }

        throw UnknownCategory::notPublished($key, $this->requireVersion(), array_slice($nearby, 0, 8));
    }

    /**
     * The jurisdiction codes a country could be addressed by, best first.
     *
     * A country is not one code. Regimes are membership over TIME, so the United
     * Kingdom is `eu:GB` while it was a member and `europe:GB` after — and the
     * register is explicit that its RATES sit at `europe:GB` for every date,
     * including 1994. Following membership alone for a 2019 British invoice finds
     * four exemptions and no standard rate at all.
     *
     * So this returns candidates rather than an answer: the regime whose membership
     * covers the date first, then every other regime that ever claimed the country.
     * The caller takes the first that actually carries a rate, which is the only
     * test that cannot be wrong about this.
     *
     * A MEMBER IS ONLY A CANDIDATE IF IT IS A COUNTRY. The `us` regime lists all
     * fifty-four states as members, and matching a code by its trailing ISO alone
     * made `us:GA` an answer for Gabon, `us:IL` for Israel and `us:CA` for Canada —
     * twenty-five countries priced at a US state's rate and stamped `Authoritative`.
     * Which one won came down to the order regimes happened to appear in, so the EU
     * escaped and everything listed after `us` did not. The register labels each
     * jurisdiction's `level`, and a `state` is never the answer to "what does this
     * COUNTRY charge".
     *
     * Where the level cannot be read the member is kept, because an unlabelled
     * jurisdiction is a gap in what we know and not evidence that it is sub-national.
     *
     * @return list<string>
     */
    public function codesForCountry(string $iso, ?DateTimeImmutable $at = null): array
    {
        $on = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $current = [];
        $other = [];

        foreach (Shape::records($this->document('meta')['regimes'] ?? null) as $regime) {
            foreach (Shape::records($regime['members'] ?? null) as $member) {
                $code = Shape::text($member['code'] ?? null);

                if ($code === null || ! str_ends_with($code, ':'.$iso)) {
                    continue;
                }

                $level = Shape::text($this->jurisdiction($code)['level'] ?? null);

                if ($level !== null && $level !== 'country') {
                    continue;
                }

                $from = $member['from'] ?? null;
                $until = $member['until'] ?? null;
                $covers = (! is_string($from) || $from <= $on) && (! is_string($until) || $until >= $on);

                if ($covers) {
                    $current[] = $code;

                    continue;
                }

                $other[] = $code;
            }
        }

        return array_values(array_unique([...$current, ...$other]));
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return $this->document('manifest');
    }

    /**
     * @return array<string, mixed>
     */
    public function coverage(): array
    {
        return $this->document('coverage');
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $name): array
    {
        return $this->readDocument($name, $this->requireVersion());
    }

    /** @return array<string, mixed> */
    private function readDocument(string $name, string $version): array
    {
        if (isset($this->documents[$name])) {
            return $this->documents[$name];
        }

        $raw = @file_get_contents($this->layout->file($version, $name.'.json'));
        $decoded = $raw === false ? null : json_decode($raw, true);

        /** @var array<string, mixed> $document */
        $document = is_array($decoded) ? $decoded : [];

        return $this->documents[$name] = $document;
    }
}
