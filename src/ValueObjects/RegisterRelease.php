<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use DateTimeImmutable;

/**
 * The register release the engine prices with — what an invoice, a support ticket or
 * an audit trail names as the source of a rate.
 */
readonly class RegisterRelease
{
    public function __construct(
        /** `2026.09.25-310`. */
        public string $version,
        /** The register's schema, `2.6.2`. */
        public string $schemaVersion,
        /** When the register published the release. */
        public ?DateTimeImmutable $publishedAt = null,
        /** When this installation compiled it to disk. */
        public ?DateTimeImmutable $compiledAt = null,
        /** The release's content hash, as the register states it. */
        public ?string $contentHash = null,
    ) {}
}
