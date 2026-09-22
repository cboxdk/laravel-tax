<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Tax\Enums\DeliveryComponent;

/** Transaction facts for a delivery charge; legal conditions remain sourced data. */
final readonly class DeliveryCharge
{
    public function __construct(
        public DeliveryComponent $component = DeliveryComponent::Transport,
        /** Host confirmation of all conditions on a published exclusion; null is unknown. */
        public ?bool $exclusionConditionsMet = null,
        /** Filled from the delivered assessment for orders; required for standalone delivery queries. */
        public ?bool $goodsTaxable = null,
    ) {}

    public function forGoods(bool $taxable): self
    {
        return new self($this->component, $this->exclusionConditionsMet, $taxable);
    }
}
