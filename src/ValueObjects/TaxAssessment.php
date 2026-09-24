<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Brick\Money\RationalMoney;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Returns\DefaultReturnAggregator;
use DateTimeImmutable;

/**
 * The engine's verdict for one supply: the treatment, the net/tax/gross split,
 * the place of supply it was taxed in, and the rate applied (null when no tax was
 * charged). `reason` is a short human-readable explanation for audit trails.
 *
 * `exemption` is set only when a buyer certificate ({@see TaxExemption}) drove the
 * outcome — an `Exempt` treatment produced by the engine applying a valid,
 * covering exemption to a would-be standard-taxed supply. It is null for every
 * other outcome, including an `Exempt` treatment that is out-of-scope rather than
 * certificate-driven (e.g. a product that is simply not taxable in the state).
 *
 * `charges` are fixed levies that are not a percentage of anything — Colorado's
 * per-order Retail Delivery Fee and its like. They sit alongside `tax` rather than
 * inside it, and {@see payable()} is what the buyer owes once they are added. See
 * {@see FlatCharge}.
 *
 * `mentions` are the legal statements the INVOICE must carry — chiefly the words
 * "Reverse charge", which Art. 226(11a) makes mandatory and the CJEU held cannot be
 * added retroactively. They are deliberately not `reason`: that is an English
 * explanation for an audit trail, and printing it on an invoice produces a
 * defective one. See {@see InvoiceMention}.
 *
 * `taxPoint` is the date the assessment resolved against — the supply date the
 * caller gave, or the day it was computed. Recording it is what makes the answer
 * auditable: a rate, a registration and a certificate were all judged as of some
 * date, and without it on the output nobody can later tell which. It is also what
 * a return needs to bucket a supply into a period.
 *
 * `breakdown` splits `tax` across the authorities that levy it, and is present
 * only when the rate source decomposed the rate it supplied. Null means the split
 * is UNKNOWN — a caller filing per jurisdiction must treat it as missing data, not
 * as one authority taking the whole amount.
 */
readonly class TaxAssessment
{
    public function __construct(
        public TaxTreatment $treatment,
        public Money $net,
        public Money $tax,
        public Money $gross,
        /** Where the supply is taxed — for a charge split into `portions`, the first portion's; see there. */
        public Jurisdiction $placeOfSupply,
        public ?TaxRate $rate,
        public string $reason,
        public ?TaxExemption $exemption = null,
        public ?TaxBreakdown $breakdown = null,
        public ?DateTimeImmutable $taxPoint = null,
        /** The date that decides which return period this supply belongs to. */
        public ?DateTimeImmutable $reportedOn = null,
        /** @var list<InvoiceMention> Legal statements the invoice must carry. */
        public array $mentions = [],
        /** @var list<FlatCharge> Fixed levies on top of the rate-based tax. */
        public array $charges = [],
        /** Policy and exact amount used to reconcile invoice rounding. */
        public ?TaxRounding $rounding = null,
        public ?RationalMoney $unroundedTax = null,
        /**
         * The parts of a charge shared between supplies taxed differently — a
         * delivery split across a standard-rated and a zero-rated line, or across
         * lines taxed in different places. Each carries its own place, treatment,
         * rate and breakdown, and they add up to this assessment exactly.
         *
         * WHERE THIS IS NOT EMPTY, `placeOfSupply`, `treatment` and `rate` describe
         * the charge as one line on an invoice, not where or how it is taxed: the
         * place is the first portion's, the treatment is Standard if any part is
         * taxed, and the rate is null when the portions differ. Book, file or remit a
         * split charge by its portions — {@see DefaultReturnAggregator}
         * does — or the whole charge lands in the first portion's country and box.
         *
         * @var list<TaxAssessment>
         */
        public array $portions = [],
        /** The taxable net base, retained even when rounded line tax is zero. */
        public ?Money $taxableBase = null,
    ) {}

    /**
     * A copy with some fields replaced and every other one carried across.
     *
     * The calculator refines an assessment in passes — stamp the dates, apply an
     * exemption, attach charges — and each pass used to rebuild the object by hand,
     * naming all thirteen fields. That is a shape that silently drops whatever was
     * added most recently: the tax-point pass listed twelve of them and omitted
     * `charges`, which was harmless only because nothing had set charges by the time
     * it ran. The next field added would not have been so lucky.
     *
     * `null` is a legitimate value for most of these, so "unchanged" cannot be
     * signalled by null. Sentinel defaults are used instead, and each parameter is
     * either given or left alone.
     *
     * @param  list<InvoiceMention>|null  $mentions
     * @param  list<FlatCharge>|null  $charges
     * @param  list<TaxAssessment>|null  $portions
     */
    public function with(
        ?TaxTreatment $treatment = null,
        ?Money $net = null,
        ?Money $tax = null,
        ?Money $gross = null,
        ?Jurisdiction $placeOfSupply = null,
        /**
         * Replaces the rate. Null leaves it alone — so this can substitute a rate
         * but never clear one, which is the only direction anything needs.
         */
        ?TaxRate $rate = null,
        ?string $reason = null,
        ?DateTimeImmutable $taxPoint = null,
        ?DateTimeImmutable $reportedOn = null,
        ?array $mentions = null,
        ?array $charges = null,
        TaxBreakdown|false|null $breakdown = false,
        ?array $portions = null,
        ?Money $taxableBase = null,
    ): self {
        return new self(
            treatment: $treatment ?? $this->treatment,
            net: $net ?? $this->net,
            tax: $tax ?? $this->tax,
            gross: $gross ?? $this->gross,
            placeOfSupply: $placeOfSupply ?? $this->placeOfSupply,
            rate: $rate ?? $this->rate,
            reason: $reason ?? $this->reason,
            exemption: $this->exemption,
            breakdown: $breakdown === false ? $this->breakdown : $breakdown,
            taxPoint: $taxPoint ?? $this->taxPoint,
            reportedOn: $reportedOn ?? $this->reportedOn,
            mentions: $mentions ?? $this->mentions,
            charges: $charges ?? $this->charges,
            rounding: $this->rounding,
            unroundedTax: $this->unroundedTax,
            portions: $portions ?? $this->portions,
            taxableBase: $taxableBase ?? $this->taxableBase,
        );
    }

    /**
     * The fixed charges the buyer is billed for — excluding any the seller must
     * absorb. Null when there are none, since there is no currency to total in.
     */
    public function chargesTotal(): ?Money
    {
        $total = null;

        foreach ($this->charges as $charge) {
            if (! $charge->passedToBuyer) {
                continue;
            }

            $total = $total === null ? $charge->amount : $total->plus($charge->amount);
        }

        return $total;
    }

    /**
     * What the buyer pays: the gross plus any fixed charges billed on.
     *
     * `gross` deliberately stays `net + tax` — that invariant holds throughout the
     * engine and several things depend on it — so a fixed charge is added here
     * rather than folded in there. A charge the seller absorbs is excluded, because
     * it is not something the buyer owes.
     */
    public function payable(): Money
    {
        $charges = $this->chargesTotal();

        return $charges === null ? $this->gross : $this->gross->plus($charges);
    }

    /**
     * The invoice mentions as printable lines, in order.
     *
     * @return list<string>
     */
    public function mentionLines(): array
    {
        return array_map(static fn (InvoiceMention $m): string => $m->line(), $this->mentions);
    }

    public function isTaxable(): bool
    {
        return $this->treatment === TaxTreatment::Standard;
    }

    /**
     * Whether the seller charges nothing because the CUSTOMER accounts for the tax.
     *
     * True for an intra-Community supply of goods as well, which is a different
     * provision reaching the same invoice: the seller charges nothing and the
     * customer self-accounts. Code that asks this question is asking about the
     * invoice, and the answer has not changed — {@see isIntraCommunitySupply()}
     * tells the two apart where the return or the EC Sales List needs it.
     */
    public function isReverseCharge(): bool
    {
        return $this->treatment === TaxTreatment::ReverseCharge
            || $this->treatment === TaxTreatment::IntraCommunitySupply;
    }

    /** An Art. 138 exempt supply of goods, as opposed to an Art. 196 reverse charge. */
    public function isIntraCommunitySupply(): bool
    {
        return $this->treatment === TaxTreatment::IntraCommunitySupply;
    }

    public function isExempt(): bool
    {
        return $this->treatment === TaxTreatment::Exempt;
    }
}
