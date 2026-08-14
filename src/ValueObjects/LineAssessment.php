<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * One line's verdict, tied back to the line the host sent.
 *
 * The `id` is the point. Without it a caller gets an ordered list and has to trust
 * the ordering to match what it sent — which works until a line is filtered, and
 * then puts one line's tax on another line's invoice row.
 */
readonly class LineAssessment
{
    public function __construct(
        public string $id,
        public TaxAssessment $assessment,
    ) {}
}
