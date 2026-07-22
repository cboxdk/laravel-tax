<?php

declare(strict_types=1);

namespace Cbox\Tax\UsTaxData;

use Cbox\Tax\Enums\TaxabilityTreatment;

/**
 * One state's taxability determination for a product category, as carried by the
 * us-tax-data dataset: the {@see TaxabilityTreatment} and any structured
 * `conditions` (a reduced rate, or a threshold such as an exempt-below price) that
 * a coarse taxable boolean cannot represent.
 */
readonly class TaxabilityDetermination
{
    /**
     * @param  array<array-key, mixed>|null  $conditions
     */
    public function __construct(
        public TaxabilityTreatment $treatment,
        public bool $taxable,
        public ?array $conditions = null,
    ) {}
}
