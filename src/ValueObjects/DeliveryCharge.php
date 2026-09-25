<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Tax\Enums\DeliveryComponent;

/** Transaction facts for a delivery charge; legal conditions remain sourced data. */
readonly class DeliveryCharge
{
    public function __construct(
        public DeliveryComponent $component = DeliveryComponent::Transport,
        /** Host confirmation of all conditions on a published exclusion; null is unknown. */
        public ?bool $exclusionConditionsMet = null,
        /** Filled from the delivered assessment for orders; required for standalone delivery queries. */
        public ?bool $goodsTaxable = null,
        /**
         * Facts for a state that publishes its delivery rule as a decision, under the
         * register's own names: `delivery.purpose`, `delivery.isDirectMail`,
         * `delivery.separatelyStated`, `delivery.label`, `delivery.costIsTrueAndReasonable`.
         * `delivery.containsExemptGoods` is inferred from the delivered goods when not
         * stated. A decision that reaches a fact nobody supplied refuses and names it.
         */
        public DecisionFacts $facts = new DecisionFacts,
    ) {}

    public function forGoods(bool $taxable): self
    {
        return new self($this->component, $this->exclusionConditionsMet, $taxable, $this->facts);
    }
}
