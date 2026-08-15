<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * Which published data answered a rate, precisely enough to find this assessment
 * again after that data is corrected.
 *
 * WHY A VERSION ALONE IS THE WEAK HALF. A dataset republish moves the content hash
 * for every state at once, so "everything priced before v2026-08-15" is millions of
 * invoices of which almost none are affected. An alarm at that resolution is one
 * people learn to close.
 *
 * What makes a reconciliation precise is `effectiveFrom` — the START OF THE WINDOW
 * the rate was read from. That is the fact the answer rested on. When a correction
 * lands, it names the windows it changed, and the invoices to re-examine are the
 * ones whose provenance points at one of them. Everything else can be left alone
 * with a straight face.
 *
 * THE RE-RUN IS THE ACTUAL ANSWER, and this is the cheap filter in front of it.
 * Keep the {@see TaxQuery} and you can re-assess at any time; because `suppliedAt`
 * reprices at the SUPPLY's date, re-running a March invoice today yields March's law
 * as currently understood. The difference between that and what was charged is the
 * reconciliation. An engine that only knew today's rate could not produce it at all.
 *
 * ONE DISTINCTION THE RE-RUN CANNOT MAKE FOR YOU. A difference is not proof of an
 * error. A state can change a rate with retroactive effect, and an ambiguity the
 * publisher later resolves changes the answer without anything having been wrong at
 * the time. Reconciliation has to separate "we were mistaken" from "the law moved
 * behind us", or it issues credit notes against correct invoices.
 *
 * Null fields are honest: a source that publishes no manifest — the static table, a
 * local directory holding only the section files — has no version to record, and the
 * absence says the answer cannot be traced to a published artifact. A local MIRROR
 * of the published data does carry its manifest, and its version is read from there;
 * what a local path skips is verification, which is a trust decision, not the
 * recording of what was read.
 */
readonly class RateProvenance
{
    public function __construct(
        /** The publisher — `us-tax-data`, `eu-tax-dataset`. */
        public string $dataset,
        /** The published version that answered, as the manifest states it. */
        public ?string $version = null,
        /**
         * The start of the dated window the rate was read from.
         *
         * The precise handle. A correction names windows; this says which one this
         * assessment stood on.
         */
        public ?string $effectiveFrom = null,
        /**
         * The sha256 of the section actually read.
         *
         * Finer than the manifest's overall content hash: a taxability correction
         * moves the whole artifact's hash but not the `rates` section's, so an
         * assessment that only read rates can be ruled out without re-running it.
         */
        public ?string $sectionHash = null,
    ) {}

    /**
     * Whether this answer can be traced back to a published artifact.
     *
     * False for a local mirror or a static table — not a fault, but an assessment
     * that cannot participate in a reconciliation, and a caller building one should
     * be able to count them rather than silently skip them.
     */
    public function isTraceable(): bool
    {
        return $this->version !== null;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'dataset' => $this->dataset,
            'version' => $this->version,
            'effectiveFrom' => $this->effectiveFrom,
            'sectionHash' => $this->sectionHash,
        ];
    }
}
