<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Math\BigDecimal;
use Cbox\Tax\Enums\PurchaserExemptionEffect;
use Cbox\Tax\Enums\PurchaserExemptionRoute;
use Cbox\Tax\Enums\PurchaserExemptionStatus;

/**
 * A place's answer for one purchaser on one supply: whether its rule applies, what it
 * does, who accounts for what is left, and what the seller must hold to rely on it.
 */
readonly class PurchaserExemption
{
    /**
     * @param  list<UnsettledCondition>  $unsettled  What the supply's facts left open.
     */
    public function __construct(
        public PurchaserExemptionStatus $status = PurchaserExemptionStatus::NotPublished,
        public PurchaserExemptionEffect $effect = PurchaserExemptionEffect::Taxable,
        /** The rate where the effect is `Reduced`. */
        public ?BigDecimal $rate = null,
        /** The purchaser, not the seller, accounts for what is due. */
        public bool $purchaserAccounts = false,
        public PurchaserExemptionRoute $route = PurchaserExemptionRoute::AtSource,
        /** Whether a certificate is needed; null where that turns on an unknown fact. */
        public ?bool $certificateRequired = null,
        /** The certificate the place names, e.g. Texas form 01-339. */
        public ?string $certificateForm = null,
        /** The provision an invoice can name. */
        public ?string $citation = null,
        /** The sentence the exemption rests on, verbatim. */
        public ?string $says = null,
        public array $unsettled = [],
    ) {}
}
