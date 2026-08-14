<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\SourcingRules;

/**
 * Where a supply came FROM and the other places it touched.
 *
 * {@see TaxQuery::$place} is the destination — where the customer is, the ship-to.
 * That was the engine's entire geography, and it is why {@see SourcingRules} shipped
 * bound, backed by a whole dataset section, and read by nothing: nine US states
 * source an in-state sale at the SELLER's location, and there was no field to
 * source from. A Houston seller shipping to an unincorporated Harris County address
 * got the buyer's 6.25% where Texas wants the seller's 8.25%.
 *
 * Every role is optional and additive. Supplying none keeps the previous behaviour
 * exactly — destination everywhere — so this widens what can be expressed without
 * changing what an existing caller gets.
 *
 * The mature engines carry four to eight of these (Vertex four, AvaTax eight, Sovos
 * seven plus a secondary situs). These are the ones the shipped regimes can
 * actually use today; adding a role nothing reads would repeat the mistake this
 * class exists to fix.
 */
readonly class SupplyRoute
{
    public function __construct(
        /**
         * Where the goods shipped from, or the service was provided from — the
         * PHYSICAL origin. This is what an origin-sourced US state taxes against.
         */
        public ?Jurisdiction $shipFrom = null,
        /**
         * Where the seller accepted the order and became contractually obliged.
         * Some US states source on this rather than on the physical origin; a few
         * more recognise it only for certain supplies. Carried so a host that knows
         * it can supply it, and used only where a rule calls for it.
         */
        public ?Jurisdiction $orderAcceptance = null,
        /**
         * The customer's administrative address, where it differs from where the
         * supply is received. Distinct from the destination: a company may be billed
         * at a head office and served somewhere else entirely.
         */
        public ?Jurisdiction $billTo = null,
    ) {}

    /**
     * The seller-side place an origin rule should tax against: the physical origin
     * if known, else where the order was accepted.
     *
     * Physical origin first because that is the rule the origin-sourcing states
     * actually write; order-acceptance is the fallback for a seller who knows only
     * which office took the order.
     */
    public function origin(): ?Jurisdiction
    {
        return $this->shipFrom ?? $this->orderAcceptance;
    }

    public function isEmpty(): bool
    {
        return $this->shipFrom === null && $this->orderAcceptance === null && $this->billTo === null;
    }
}
