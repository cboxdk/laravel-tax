<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Cbox\Tax\Enums\RefusalReason;
use RuntimeException;

/** A published rule cannot be applied without inventing semantics or facts. */
class UnresolvedTaxRule extends RuntimeException implements Refusal
{
    public function __construct(string $message, private readonly RefusalReason $refusalReason = RefusalReason::TaxRuleUnsupported)
    {
        parent::__construct($message);
    }

    public function reason(): RefusalReason
    {
        return $this->refusalReason;
    }
}
