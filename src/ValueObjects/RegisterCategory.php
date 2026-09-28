<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * One category of the register's vocabulary: `goods.publications.book`, named "Books",
 * under `goods.publications`.
 */
readonly class RegisterCategory
{
    public function __construct(
        public string $key,
        public string $name,
        public ?string $parent = null,
    ) {}
}
