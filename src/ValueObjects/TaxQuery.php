<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use DateTimeImmutable;

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
 * `commodityCode` optionally carries the supply's **CN code** (goods) or **CPA
 * code** (services) — the classifications the EU Commission's own rate database
 * keys its scopes by. It refines, never restricts: a source that can use it
 * ({@see CommodityRateSource}) resolves reduced bands the
 * coarse {@see TaxCategory} cannot, because a category like `grocery` genuinely
 * carries several rates in half the member states. Absent or unrecognised, the
 * category alone decides exactly as before.
 *
 * `suppliedAt` is the **tax point** — the date the supply took place, which is the
 * date the law asks about. Null means today, which is right for a fresh invoice
 * and wrong for everything else: a credit note against a March invoice, a reissue,
 * or a correction must reprice at the rate that applied *then*, not now. Every rate
 * source already accepts a date; this is what finally supplies one. It also decides
 * whether a buyer's exemption certificate was valid at the time of supply rather
 * than at the time someone happened to run the calculation.
 *
 * `reportedOn` is the date that decides which RETURN PERIOD the supply falls into,
 * and it is not always the tax point. Goods supplied on 30 December and invoiced on
 * 3 January are rated at December's rate, while national tax-point rules may put
 * them in either period — so one date cannot do both jobs, and conflating them
 * silently misfiles every invoice that straddles a period end. Null follows the tax
 * point, which is right whenever they agree.
 *
 * `route` carries the OTHER places a supply touched — chiefly where it shipped
 * from. `place` above is the destination, and for a long time it was the only
 * geography the engine had, which is why nine US states that source an in-state
 * sale at the seller's location could not be served correctly. See
 * {@see SupplyRoute}.
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
        public TaxClass $category = TaxClass::GeneralGoods,
        public bool $customerTaxIdValidated = false,
        public ?TaxExemption $exemption = null,
        public ?string $commodityCode = null,
        public ?DateTimeImmutable $suppliedAt = null,
        /** Which return period the supply falls into. Null follows the tax point. */
        public ?DateTimeImmutable $reportedOn = null,
        /** Where the supply came from, and the other places it touched. */
        public SupplyRoute $route = new SupplyRoute,
        /**
         * The delivery postcode, where the caller has one.
         *
         * It is here because a country code is not always enough to know which tax
         * applies. Ten EU territories sit inside a member state and outside its VAT
         * rules — the Canary Islands, Ceuta, Melilla, Åland, Livigno and the rest —
         * and none of them can be named by subdivision, because the addressing
         * reference data carries no subdivisions for Portugal, Finland, France or
         * Greece at all. Every one of them has its own postal range.
         *
         * Null is honest and common: most callers have no postcode at hand, and the
         * engine then applies the national rules, which are right for the
         * overwhelming majority of addresses. What it must not do is treat a
         * missing postcode as proof of mainland.
         */
        public ?string $postalCode = null,
    ) {}

    /** The date the assessment resolves against — the supply date, else today. */
    public function on(): DateTimeImmutable
    {
        return $this->suppliedAt ?? new DateTimeImmutable;
    }

    /** The date deciding which return period this supply belongs to. */
    public function reportingDate(): DateTimeImmutable
    {
        return $this->reportedOn ?? $this->on();
    }

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
