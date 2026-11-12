<?php

declare(strict_types=1);

namespace Cbox\Tax\Testing;

/**
 * One place two datasets answer differently, found by {@see DatasetComparison}.
 *
 * `where` reads as the question asked: `rates[eu:DK][3].percentage`,
 * `jurisdiction[us:TX:453].name`, `boundary[TX.zip][73301]`. The two values are
 * rendered as text and cut at 240 characters; they are for a person reading a
 * report, not for comparing again.
 */
readonly class DatasetDifference implements \Stringable
{
    public function __construct(
        public string $where = '',
        public string $left = '',
        public string $right = '',
    ) {}

    public function __toString(): string
    {
        return sprintf('%s: %s != %s', $this->where, $this->left, $this->right);
    }
}
