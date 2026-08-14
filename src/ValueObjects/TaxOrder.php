<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Exceptions\InvalidTaxOrder;
use DateTimeImmutable;

/**
 * A document to assess: the context every line shares, plus the lines themselves.
 *
 * A real invoice is multi-line and the lines are not independent — a subscription,
 * metered usage and one-off onboarding services are three tax categories on one
 * document, settled once, rounded once, filed as one supply. Assessing them as
 * three separate {@see TaxQuery} calls rounds three times and produces three
 * assessments nothing ties back together.
 *
 * The order plane adds NO tax logic. {@see queryFor()} is the single place a line
 * becomes a single-supply query, so every regime, rate source, gate and refusal
 * applies exactly as it does for one amount. That is the whole design: fan out,
 * then sum.
 */
readonly class TaxOrder
{
    /**
     * @param  list<SupplyLine>  $lines
     *
     * @throws InvalidTaxOrder When there are no lines, or they mix currencies.
     */
    public function __construct(
        public Jurisdiction $place,
        public CustomerType $customer,
        public SellerRegistrations $seller,
        public Pricing $pricing,
        public array $lines,
        public bool $customerTaxIdValidated = false,
        /** Applies to every line that does not carry its own. */
        public ?TaxExemption $exemption = null,
        public ?DateTimeImmutable $suppliedAt = null,
        /** Which return period the document falls into. Null follows the tax point. */
        public ?DateTimeImmutable $reportedOn = null,
        /** Shared by every line: a document ships from one place. */
        public SupplyRoute $route = new SupplyRoute,
    ) {
        if ($lines === []) {
            throw InvalidTaxOrder::withoutLines();
        }

        // One document, one currency. Every mature tax API holds this — a document
        // carries a single currencyCode — and for good reason: the totals, the
        // return line and the filing are all in one currency, and Money refuses to
        // add across currencies anyway. Catching it here names the problem instead
        // of surfacing it as a MoneyMismatchException three layers down.
        $currency = $lines[0]->amount->getCurrency()->getCurrencyCode();
        $seen = [];

        foreach ($lines as $line) {
            $lineCurrency = $line->amount->getCurrency()->getCurrencyCode();

            if ($lineCurrency !== $currency) {
                throw InvalidTaxOrder::mixedCurrencies($currency, $lineCurrency, $line->id);
            }

            // The id is how a host maps tax back onto its own invoice rows. Two
            // lines sharing one means the totals count both while a lookup finds
            // only the first, so the second line's tax lands on the first line's
            // row and nothing anywhere says so. An empty id fails the same way.
            if ($line->id === '') {
                throw InvalidTaxOrder::unidentifiedLine();
            }

            if (isset($seen[$line->id])) {
                throw InvalidTaxOrder::duplicateLineId($line->id);
            }

            $seen[$line->id] = true;
        }
    }

    /**
     * The single-supply query for one line — the ONLY place order context becomes
     * a query, so the two planes cannot drift apart.
     */
    public function queryFor(SupplyLine $line): TaxQuery
    {
        return new TaxQuery(
            amount: $line->amount,
            pricing: $line->pricing ?? $this->pricing,
            place: $this->place,
            customer: $this->customer,
            seller: $this->seller,
            category: $line->category,
            customerTaxIdValidated: $this->customerTaxIdValidated,
            exemption: $line->exemption ?? $this->exemption,
            commodityCode: $line->commodityCode,
            suppliedAt: $this->suppliedAt,
            reportedOn: $this->reportedOn,
            route: $this->route,
        );
    }

    public function currency(): string
    {
        return $this->lines[0]->amount->getCurrency()->getCurrencyCode();
    }
}
