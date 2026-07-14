<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\TaxTreatment;

/**
 * The engine's verdict for one supply: the treatment, the net/tax/gross split,
 * the place of supply it was taxed in, and the rate applied (null when no tax was
 * charged). `reason` is a short human-readable explanation for audit trails.
 */
readonly class TaxAssessment
{
    public function __construct(
        public TaxTreatment $treatment,
        public Money $net,
        public Money $tax,
        public Money $gross,
        public Jurisdiction $placeOfSupply,
        public ?TaxRate $rate,
        public string $reason,
    ) {}

    public function isTaxable(): bool
    {
        return $this->treatment === TaxTreatment::Standard;
    }

    public function isReverseCharge(): bool
    {
        return $this->treatment === TaxTreatment::ReverseCharge;
    }
}
