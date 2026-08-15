<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Contracts\ProductCatalogue;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
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
        /**
         * The supply was made through a marketplace that is the party liable to
         * collect the tax on it.
         *
         * Every US state with a sales tax now makes a qualifying marketplace liable
         * for its third-party sellers' supplies — Missouri was the last, on
         * 2023-01-01 — and the EU does the same through the Art. 14a deemed supplier
         * rule. Where that applies, the SELLER charges nothing: the marketplace has
         * already collected it, and a seller who charges as well double-charges the
         * customer.
         *
         * ONLY THE CALLER KNOWS THIS. Whether a given platform qualifies as a
         * facilitator, and whether it has taken on collection for this supply, is a
         * fact about a commercial arrangement — nothing in a rate table can answer
         * it. The engine takes the assertion and then checks the LAW: it applies the
         * marketplace treatment only where the jurisdiction's rule was in force on
         * the supply date, so a backdated Missouri sale from 2022 is still the
         * seller's to collect.
         *
         * False is right for a direct sale, which is most of them.
         */
        public bool $marketplaceFacilitated = false,
        /**
         * Your own code for what was sold — a SKU, a plan id, a product slug.
         *
         * The point of it is that the tax class stops being a decision made while
         * building an invoice. Resolved through a {@see ProductCatalogue} you
         * populate once per product, so ten thousand SKUs are ten thousand recorded
         * facts rather than ten thousand chances to pick differently.
         *
         * Resolution is three-deep, most specific first: a `category` stated here
         * overrides everything, then the catalogue keyed by this code, then the
         * fallback. A code nothing has mapped still produces an invoice — and says
         * so, via {@see RateLimit::ItemUnmapped}, so it can be found and fixed
         * rather than quietly taxed at the standard rate forever.
         */
        public ?string $itemCode = null,
    ) {}

    /**
     * The same query with the classification filled in from a product catalogue.
     *
     * Narrow on purpose. A general `with()` over fifteen constructor parameters is
     * a method nobody can read and every future field has to be threaded through;
     * this copies the two fields the catalogue owns and nothing else, so adding a
     * parameter to the constructor cannot silently drop it here.
     */
    public function classifiedAs(TaxClass $category, ?string $commodityCode): self
    {
        return new self(
            $this->amount,
            $this->pricing,
            $this->place,
            $this->customer,
            $this->seller,
            $category,
            $this->customerTaxIdValidated,
            $this->exemption,
            $commodityCode,
            $this->suppliedAt,
            $this->reportedOn,
            $this->route,
            $this->postalCode,
            $this->marketplaceFacilitated,
            $this->itemCode,
        );
    }

    /**
     * The date the assessment resolves against — the supply date, else today.
     *
     * TREATED AS A CALENDAR DATE IN THE JURISDICTION, not as an instant to be
     * converted. `2026-08-07` means the seventh of August where the supply happened,
     * and the engine does not shift it into a timezone: a tax point is a legal fact
     * the seller determines, and second-guessing a stated date by moving it across a
     * boundary would exempt a supply a day early.
     *
     * THAT PUTS A REAL OBLIGATION ON THE CALLER, so it is stated here rather than
     * left to be discovered. `suppliedAt` must carry the supply's own date. A bare
     * `new DateTimeImmutable` picks up the application timezone, and Laravel's
     * default is UTC — ahead of every US state by four to seven hours. A Texas sale
     * at 20:00 local on 6 August is 01:00 UTC on the 7th, and passing that instant
     * unchanged puts it inside a holiday window that had not opened, or on the far
     * side of a rate change that had not taken effect.
     *
     * Where a date boundary decides an outcome — a sales tax holiday, the day a
     * marketplace-facilitator rule took effect, a rate change at midnight — supply
     * the date as the jurisdiction reckons it. Null is right for an invoice raised
     * now in your own jurisdiction and wrong for everything else.
     */
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
