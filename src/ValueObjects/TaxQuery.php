<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxCategory;

/**
 * Everything the engine needs to assess one supply: the amount, whether it is
 * net or gross, where the customer belongs (place of supply, resolved from
 * {@see JurisdictionRepository}), who they are, what is being
 * supplied, and the selling entity's tax standing.
 *
 * `customerTaxIdValidated` records that the business customer's tax ID was
 * verified (e.g. via VIES for the EU) — reverse-charge zero-rating legally hinges
 * on it, so the engine only applies reverse-charge when it is true.
 *
 * `exemption` carries an optional buyer tax exemption ({@see TaxExemption}) the
 * consumer has captured and verified — a resale/nonprofit/government certificate.
 * The engine applies it deny-by-default: it only exempts a would-be standard-taxed
 * supply, and only when the exemption is valid and covers the place of supply. The
 * consumer owns certificate capture and verification; the engine owns the
 * assessment.
 */
readonly class TaxQuery
{
    public function __construct(
        public Money $amount,
        public Pricing $pricing,
        public Jurisdiction $place,
        public CustomerType $customer,
        public SellerRegistrations $seller,
        public TaxCategory $category = TaxCategory::Standard,
        public bool $customerTaxIdValidated = false,
        public ?TaxExemption $exemption = null,
    ) {}

    public function isBusiness(): bool
    {
        return $this->customer === CustomerType::Business;
    }

    /** Cross-border when the selling entity is not established in the customer's country. */
    public function isCrossBorder(): bool
    {
        return ! $this->seller->isEstablishedIn($this->place->country);
    }
}
