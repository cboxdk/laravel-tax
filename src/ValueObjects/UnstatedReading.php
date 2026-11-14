<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Tax\Contracts\UnstatedReadings;

/**
 * One `read_unstated` record (register schema 2.8): a source was read for a category,
 * in the sections named, on a day, and the law it holds does not answer it.
 *
 * Not a rate and not an exemption. It carries no figure and no effect; see
 * {@see UnstatedReadings}.
 */
readonly class UnstatedReading
{
    public function __construct(
        /** The register's code for the place the source was read for, e.g. `us:TX`. */
        public string $jurisdiction,
        /** The register category the source was read for. */
        public string $category,
        /** What the reading found, in one line, in the register's words. */
        public string $finding,
        /**
         * What was read, as the publisher numbers it: sections, rules, or the
         * document where the whole of it was read.
         *
         * @var list<string>
         */
        public array $sections,
        /** The day the source was read, `Y-m-d`. */
        public string $readOn,
        /** True where the day was not recorded: the source was read on or before {@see $readOn}. */
        public bool $readOnIsCeiling = false,
        /** The register source that was read; null where the release does not name it. */
        public ?string $source = null,
    ) {}
}
