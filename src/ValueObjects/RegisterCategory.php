<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * One category of the register's vocabulary: `goods.publications.book`, named "Books",
 * under `goods.publications`.
 */
readonly class RegisterCategory
{
    /**
     * @param  list<string>  $regions  The regimes the category is used in: `eu`, `us`…
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $parent = null,
        /** What the category covers, in the register's words. */
        public ?string $description = null,
        public array $regions = [],
        /** The text the category is drawn from, e.g. `VAT Directive Annex III (1)`. */
        public ?string $cites = null,
    ) {}
}
