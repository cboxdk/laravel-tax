<?php

declare(strict_types=1);

namespace Cbox\Tax\Nexus;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Enums\NexusCombinator;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\NexusThreshold;

/**
 * Supplies US economic-nexus thresholds from the us-tax-data nexus dataset. A state
 * the dataset carries returns its threshold; a state it does not (the no-sales-tax
 * states, or any state absent from the section) returns null, so the engine makes
 * no economic-nexus claim there — deny-by-default, never a guess.
 */
readonly class UsTaxDatasetNexus implements NexusThresholds
{
    public function __construct(private UsTaxDataset $dataset) {}

    public function for(SubdivisionCode $state): ?NexusThreshold
    {
        $nexus = $this->dataset->nexus($state->value);

        if ($nexus === null) {
            return null;
        }

        $combinator = NexusCombinator::tryFrom($nexus['combinator']);

        if ($combinator === null) {
            return null;
        }

        return new NexusThreshold($nexus['salesUsd'], $nexus['transactions'], $combinator);
    }
}
