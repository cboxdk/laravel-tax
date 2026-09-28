<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\LocalResolution;
use Cbox\Tax\Exceptions\DatasetNotInstalled;
use Cbox\Tax\Exceptions\UnknownCategory;
use Cbox\Tax\ValueObjects\DistanceSalesThreshold;
use Cbox\Tax\ValueObjects\RateRecord;
use Cbox\Tax\ValueObjects\RegisterCategory;
use Cbox\Tax\ValueObjects\RegisterRelease;
use DateTimeImmutable;

/**
 * What an application may ask the installed register directly — the release it
 * prices with, its category vocabulary, what a US state needs to be resolved, the
 * EU's distance-sales threshold, and the rate records a jurisdiction files.
 *
 * The public face of the register. The classes that read the store are internal and
 * change with it; this does not. Pricing a supply is not here — that is
 * `TaxCalculator` — and neither are the rules the engine applies on the way.
 */
interface TaxRegister
{
    /** The release the engine prices with; null when none is installed. */
    public function release(): ?RegisterRelease;

    /**
     * The category vocabulary of the installed release, in the release's own order.
     *
     * @return list<RegisterCategory>
     */
    public function categories(): array;

    /**
     * Refuse a category key the installed release does not publish.
     *
     * @throws UnknownCategory Naming the nearest keys it does publish.
     */
    public function assertCategoryPublished(string $key): void;

    /**
     * What a local answer in a US state needs, as the release states it; null where
     * it does not say. `state` is two letters: `TX`.
     */
    public function localResolution(string $state): ?LocalResolution;

    /**
     * The EU's threshold for cross-border sales to consumers, in force on the date;
     * null where the release publishes none.
     */
    public function distanceSalesThreshold(?DateTimeImmutable $at = null): ?DistanceSalesThreshold;

    /**
     * Every rate record the release files for a place: a country's own, or a
     * subdivision's where the place names one (`US-TX`, `CA-ON`).
     *
     * Another installed release may be named, to compare what changed. One that is
     * not installed is refused — nothing is fetched.
     *
     * @return list<RateRecord>
     *
     * @throws DatasetNotInstalled When the named release is not on disk.
     */
    public function rateRecords(Jurisdiction $place, ?string $release = null): array;
}
