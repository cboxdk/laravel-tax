<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Tax\Exceptions\DatasetNotInstalled;
use Cbox\Tax\Exceptions\UnknownCategory;
use Cbox\Tax\Register\Reader\DocumentDataset;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Testing\DatasetComparison;
use Cbox\Tax\Testing\DatasetConformance;
use Cbox\Tax\ValueObjects\RegisterFact;
use DateTimeImmutable;

/**
 * One release of the register, as the engine reads it.
 *
 * Everything the engine prices with comes through here: the rate source, nexus,
 * delivery, rounding, marketplace rules, the boundary resolver, the geocoder and
 * {@see TaxRegister}. The default implementation is {@see RegisterDataset}, over the
 * local store `tax:data:sync` compiles. A host that keeps the register somewhere else
 * (a database, a service of its own) binds its own implementation of this contract
 * and the engine reads that instead.
 *
 * THE RULES AN IMPLEMENTATION KEEPS.
 *
 * - ONE RELEASE PER INSTANCE. {@see version()} is resolved once and does not move for
 *   the life of the object: two lines of one order are priced by the same release.
 *   Another release is another instance, from {@see at()}.
 * - RECORDS IN THE REGISTER'S OWN SHAPE AND ORDER. Rates, jurisdictions and rules are
 *   the published records, unfiltered, in publication order. Where a category has
 *   more than one candidate rate the resolver takes the first, so order is part of
 *   the answer.
 * - NOTHING FOUND IS AN EMPTY ANSWER, NOT AN ERROR: an unknown code has no rates and
 *   no jurisdiction record. Whether a place is covered at all is {@see carries()}.
 * - NO RELEASE IS A REFUSAL: {@see requireVersion()} throws, and nothing is priced
 *   from a guess.
 *
 * Most implementations extend {@see DocumentDataset}, which derives every question
 * below from the release's small sections ({@see document()}) and leaves the
 * implementation to say where the rows, the sections and the boundary files are.
 * {@see DatasetConformance} is the test an implementation must pass, and
 * {@see DatasetComparison} proves two implementations give the same answers.
 */
interface RegisterData
{
    /** The release this instance reads, fixed for its lifetime; null when there is none. */
    public function version(): ?string;

    public function isInstalled(): bool;

    /**
     * The release, or a refusal where there is none or its schema is one this engine
     * does not read.
     *
     * @throws DatasetNotInstalled
     */
    public function requireVersion(): string;

    /**
     * Where this dataset reads from, for a refusal that can name it: a directory for
     * the file store, any locator a person can act on otherwise.
     */
    public function storeRoot(): string;

    /**
     * The same register at another release. One that is not available answers null
     * from {@see version()} and refuses from {@see requireVersion()}; nothing is
     * fetched to satisfy it.
     */
    public function at(string $release): self;

    /**
     * One of the release's small sections, whole, as the register publishes it:
     *
     * - `manifest`: `version`, `contentHash`, `schemaVersion`, `publishedAt`,
     *   `compiledAt`, `regions`, `states`
     * - `meta`: `regimes`, `categories`, `mappings`, `sources`
     * - `rules`: `rules`
     * - `standard-rates`: `standardRates`, `jurisdictions`
     * - `coverage`
     * - `us-local`: `absence`, `resolution`, `unpriced`
     * - `facts`: `formatVersion`, `facts`
     *
     * A section the release does not carry is an empty array.
     *
     * @return array<string, mixed>
     */
    public function document(string $name): array;

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array;

    /**
     * @return array<string, mixed>
     */
    public function coverage(): array;

    /**
     * Every shard the release holds records in: a regime (`eu`, `apac`) or, for the
     * United States, a state (`us/TX`, and `us/_US` for the regime itself).
     *
     * @return list<string>
     */
    public function shards(): array;

    /**
     * Every jurisdiction code a shard holds a rate or a jurisdiction record for.
     *
     * @return list<string>
     */
    public function codesIn(string $shard): array;

    /**
     * Every rate record the register holds for a jurisdiction, unfiltered, in
     * publication order. Dating, category and tax type are the resolver's job.
     *
     * @return list<array<string, mixed>>
     */
    public function ratesFor(string $jurisdiction): array;

    /**
     * @return array<string, mixed>|null
     */
    public function jurisdiction(string $code): ?array;

    /**
     * Every jurisdiction in a shard that publishes a name, as code => name.
     *
     * @return array<string, string>
     */
    public function namesIn(string $shard): array;

    /**
     * Whether the release carries this regime (and, in the United States, this
     * state) at all, as opposed to carrying it and finding nothing.
     */
    public function carries(string $jurisdiction): bool;

    /**
     * Rules that apply to a jurisdiction, in publication order; of one kind where
     * a kind is given.
     *
     * @return list<array<string, mixed>>
     */
    public function rulesFor(string $jurisdiction, ?string $kind = null): array;

    /**
     * Rules whose published window contains the date. End dates are inclusive.
     *
     * @return list<array<string, mixed>>
     */
    public function rulesOn(string $jurisdiction, string $kind, DateTimeImmutable $at): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function standardRates(string $jurisdiction): array;

    /**
     * The category vocabulary, keyed by its own key, in the release's order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function categories(): array;

    /**
     * @throws UnknownCategory Naming the nearest keys the release does publish.
     */
    public function assertCategoryPublished(string $key): void;

    /**
     * The jurisdiction codes a country could be addressed by, best first: the regime
     * whose membership covers the date, then every other regime that ever claimed it.
     *
     * @return list<string>
     */
    public function codesForCountry(string $iso, ?DateTimeImmutable $at = null): array;

    /** What a local answer in a US state needs: `state`, `county` or `address`. */
    public function usLocalResolution(string $state): ?string;

    /**
     * The county-equivalents in a US state drawn and priced at nothing local; null
     * where the release makes no claim for the state.
     *
     * @return list<array{name: string, legalName: string}>|null
     */
    public function usLocalUnpriced(string $state): ?array;

    /** Whether ground no artifact places is ground without local tax, on a date. */
    public function usLocalAbsenceHolds(string $state, DateTimeImmutable $on): bool;

    /** The question behind one of the register's fact names. */
    public function fact(string $name): ?RegisterFact;

    /** Whether the release carries a geometry artifact for a US state; null with no release. */
    public function publishesGeometryFor(string $state): ?bool;

    /** Whether the release carries a district overlay for a US state; null with no release. */
    public function publishesOverlayFor(string $state): ?bool;

    /**
     * Where one compiled boundary file of this release is on local disk: `TX.zip`,
     * `TX.zip.idx`, `TX.zip.head.json`, `KS.street`, `KS.street.sets.json`, `CA.geo`,
     * `CA.geo.boxes.json`, `CA.geo.json`, `NE.overlay.json`.
     *
     * The boundary layers are files in the store's own format whatever the rows are
     * kept in: a ZIP+4 table is read a record at a time through an index, and that is
     * a file. The path is returned whether or not the file exists; a release that
     * holds no such layer for the state simply has no file there, which the resolver
     * reads as "this register does not place that address".
     */
    public function boundaryPath(string $file): string;
}
