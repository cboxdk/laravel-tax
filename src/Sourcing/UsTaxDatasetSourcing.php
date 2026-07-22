<?php

declare(strict_types=1);

namespace Cbox\Tax\Sourcing;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Enums\SourcingMode;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\IntrastateSourcing;

/**
 * Supplies US intrastate sourcing rules from the us-tax-data sourcing dataset. A
 * state the dataset carries returns its rule; anything else (the no-sales-tax
 * states, or a state absent from the section) returns null — deny-by-default.
 */
readonly class UsTaxDatasetSourcing implements SourcingRules
{
    public function __construct(private UsTaxDataset $dataset) {}

    public function for(SubdivisionCode $state): ?IntrastateSourcing
    {
        $sourcing = $this->dataset->sourcing($state->value);

        if ($sourcing === null) {
            return null;
        }

        $mode = SourcingMode::tryFrom($sourcing['mode']);

        if ($mode === null) {
            return null;
        }

        return new IntrastateSourcing($mode, $sourcing['note']);
    }
}
