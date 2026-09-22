<?php

declare(strict_types=1);

namespace Cbox\Tax\Concerns;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\ApportionmentBasis;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\ValueObjects\DeliveryCharge;
use Cbox\Tax\ValueObjects\FlatCharge;
use Cbox\Tax\ValueObjects\LineAssessment;
use Cbox\Tax\ValueObjects\OrderAssessment;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;

/**
 * Assessing a document as a fan-out over single supplies.
 *
 * Shared by the shipped calculator and the adapter that wraps a host's own.
 * Each line becomes a query via {@see TaxOrder::queryFor()}; delivery is allocated
 * after the goods are assessed, then published invoice rounding is reconciled.
 *
 * A line that refuses throws, and the whole document fails with it. Half a
 * tax-assessed invoice is not a useful artefact — worse, it is one a caller might
 * ship.
 *
 * @phpstan-require-implements TaxCalculator
 */
trait AssessesOrders
{
    use RoundsInvoices;

    public function assessOrder(TaxOrder $order): OrderAssessment
    {
        $delivered = [];
        $lines = [];

        foreach ($order->lines as $line) {
            if ($line->isDeliveryCharge) {
                continue;
            }

            $assessed = $this->assessLine($order, $line);
            $delivered[] = [$line, $assessed];
            $lines[] = new LineAssessment($line->id, $assessed);
        }

        // Delivery is assessed only once the supplies it accompanies have been, since
        // it takes their rates rather than looking up one of its own.
        foreach ($order->lines as $line) {
            if ($line->isDeliveryCharge) {
                $lines[] = new LineAssessment($line->id, $this->assessDelivery($order, $line, $delivered));
            }
        }

        // Back into the caller's order. The fan-out above runs goods before delivery
        // out of necessity, and a host mapping assessments onto invoice rows by
        // position would otherwise find them shuffled.
        $byId = [];

        foreach ($lines as $assessed) {
            $byId[$assessed->id] = $assessed;
        }

        $assessment = new OrderAssessment($this->roundInvoice($order, array_map(
            static fn (SupplyLine $line): LineAssessment => $byId[$line->id],
            $order->lines,
        )));

        $charges = $this->orderCharges($order, $assessment);

        return $charges === [] ? $assessment : $assessment->withCharges($charges);
    }

    /**
     * A delivery charge, spread across the supplies it delivers.
     *
     * EU incidental costs follow the supplied goods under Article 78(b). Other
     * regimes may include or exclude a component under their own published rules.
     * Each portion carries delivery facts to the regime; a mixed result has no
     * single rate and retains its component assessments in `portions`.
     *
     * The split is NOT put in a `TaxBreakdown`. That structure describes which
     * authorities levied what — state, county, city — and a delivery split describes
     * which RATES a charge was divided across. Two different things wearing a
     * similar shape, and conflating them would put "5.5%" where a caller reads an
     * authority name.
     *
     * Two rounding rules, because the charge has to survive both divisions intact.
     * The shares are made exact by giving the last one whatever the others left
     * behind. And the shares are taken per RATE rather than per line, so a cart whose
     * lines all reach the same rate rounds its tax once rather than once per line —
     * see {@see apportion()}, where getting that wrong cost a minor unit.
     *
     * @param  list<array{0: SupplyLine, 1: TaxAssessment}>  $delivered
     */
    protected function assessDelivery(TaxOrder $order, SupplyLine $charge, array $delivered): TaxAssessment
    {
        $shares = $this->apportion($order, $charge, $delivered);

        $net = Money::zero($charge->amount->getCurrency(), $charge->amount->getContext());
        $tax = $net;
        $gross = $net;
        $parts = [];
        $portions = [];
        $exactTax = null;
        $rounding = null;

        foreach ($shares as [$line, $assessed, $share]) {
            // The share is assessed AS the line it accompanies — that line's class,
            // its commodity code, its exemption. That is the whole mechanism: the
            // rate is not looked up for "delivery", it is looked up for what is
            // being delivered.
            $portion = $this->assessLine($order, new SupplyLine(
                id: $charge->id,
                amount: $share,
                category: $line->category,
                pricing: $charge->pricing ?? $order->pricing,
                commodityCode: $line->commodityCode,
                exemption: $line->exemption,
                itemCode: $line->itemCode,
                isDeliveryCharge: true,
                delivery: ($charge->delivery ?? new DeliveryCharge)->forGoods($assessed->treatment->taxWasDue()),
            ));

            if ($order->place->country->value === 'US' && ! $share->isZero() && $portion->isTaxable()
                && $assessed->taxableBase !== null && $assessed->isTaxable()
                && $assessed->taxableBase->abs()->isLessThan($assessed->net->abs())) {
                throw new UnresolvedTaxRule('Taxable delivery of partially exempt goods requires a rule for allocating its taxable base.');
            }

            $portions[] = $portion;

            if ($portion->rounding !== null && $portion->unroundedTax !== null) {
                if ($rounding !== null && $rounding->key() !== $portion->rounding->key()) {
                    throw new UnresolvedTaxRule('Delivery portions have incompatible rounding policies.');
                }

                $rounding = $portion->rounding;
                $exactTax = $exactTax === null ? $portion->unroundedTax : $exactTax->plus($portion->unroundedTax);
            }

            $net = $net->plus($portion->net);
            $tax = $tax->plus($portion->tax);
            $gross = $gross->plus($portion->gross);
            $parts[] = sprintf(
                '%s at %s',
                $share->getAmount(),
                $portion->rate === null ? $portion->treatment->value : $portion->rate->percentage.'%',
            );
        }

        return new TaxAssessment(
            // No single rate, because there is no single rate — a mixed cart's
            // delivery is taxed at several. The reason carries the split instead of
            // forcing it into a breakdown built to describe authority levels, which
            // is a different thing wearing a similar shape.
            treatment: array_any($portions, static fn (TaxAssessment $portion): bool => $portion->isTaxable())
                ? TaxTreatment::Standard : $portions[0]->treatment,
            net: $net,
            tax: $tax,
            gross: $gross,
            placeOfSupply: $delivered[0][1]->placeOfSupply,
            rate: count($portions) === 1 ? $portions[0]->rate : null,
            reason: sprintf(
                'Delivery assessed under the applicable jurisdiction rules, %s: %s.',
                $order->apportionment->describe(),
                implode(', ', $parts),
            ),
            breakdown: count($portions) === 1 ? $portions[0]->breakdown : null,
            taxPoint: $portions[0]->taxPoint,
            reportedOn: $portions[0]->reportedOn,
            rounding: $rounding,
            unroundedTax: $exactTax,
            portions: $portions,
            taxableBase: count($portions) === 1 ? $portions[0]->taxableBase : null,
        );
    }

    /**
     * The delivery charge split across the delivered lines, as `[assessment, share]`.
     *
     * @param  list<array{0: SupplyLine, 1: TaxAssessment}>  $delivered
     * @return list<array{0: SupplyLine, 1: TaxAssessment, 2: Money}>
     */
    private function apportion(TaxOrder $order, SupplyLine $charge, array $delivered): array
    {
        // GROUPED BY THE ANSWER, NOT BY THE LINE. Splitting per line rounds the tax
        // once per line: three lines at 5.5% sharing a 10.00 charge yield 3.33, 3.33
        // and 3.34, each taxed to 0.18, totalling 0.54 where 10.00 at 5.5% is 0.55.
        // The remainder rule below makes the SHARES exact and does nothing for that,
        // because the loss happens a level further down.
        //
        // Lines that reach the same outcome need only one share between them, so a
        // single-rate cart has exactly one and rounds exactly once. It is also how
        // the split reads on an invoice: one delivery sub-line per rate, not per
        // product.
        $groups = [];

        foreach ($delivered as [$line, $assessed]) {
            $key = $assessed->treatment->value.'|'.((string) $assessed->rate?->percentage);

            $weight = match ($order->apportionment) {
                ApportionmentBasis::Equal => Money::of(1, $charge->amount->getCurrency()),
                ApportionmentBasis::NetValue => $assessed->net->abs(),
                ApportionmentBasis::GrossValue => $assessed->gross->abs(),
            };

            if (! isset($groups[$key])) {
                $groups[$key] = [$line, $assessed, $weight];

                continue;
            }

            $groups[$key][2] = $groups[$key][2]->plus($weight);
        }

        $delivered = [];
        $weights = [];
        $total = null;

        foreach ($groups as [$line, $assessed, $weight]) {
            $delivered[] = [$line, $assessed];
            $weights[] = $weight;
            $total = $total === null ? $weight : $total->plus($weight);
        }

        $count = count($delivered);

        // Every delivered line free, or an equal split of nothing: there is no ratio
        // to divide by. An equal share is the only defensible answer and it is still
        // exact, because the remainder rule below closes any gap.
        $useEqual = $total === null || $total->isZero();

        $shares = [];
        $allocated = null;

        foreach ($delivered as $index => [$line, $assessed]) {
            if ($index === $count - 1) {
                $share = $allocated === null ? $charge->amount : $charge->amount->minus($allocated);
            } elseif ($useEqual) {
                $share = $charge->amount->dividedBy($count, RoundingMode::HalfUp);
            } else {
                $ratio = $weights[$index]->getAmount()->toBigDecimal()
                    ->dividedBy($total->getAmount()->toBigDecimal(), 12, RoundingMode::HalfUp);
                $share = $charge->amount->multipliedBy($ratio, RoundingMode::HalfUp);
            }

            $allocated = $allocated === null ? $share : $allocated->plus($share);
            $shares[] = [$line, $assessed, $share];
        }

        return $shares;
    }

    /**
     * Assess one line of the document.
     *
     * Overridable because a per-supply concern can be wrong at document level: the
     * shipped calculator suppresses per-supply flat charges here and levies the
     * document's own separately, so a per-delivery fee is charged once rather than
     * once per line.
     */
    protected function assessLine(TaxOrder $order, SupplyLine $line): TaxAssessment
    {
        return $this->assess($order->queryFor($line));
    }

    /**
     * The charges levied on the document as a whole. None by default — a host
     * calculator wrapped by the fan-out has no document-level source to ask.
     *
     * @return list<FlatCharge>
     */
    protected function orderCharges(TaxOrder $order, OrderAssessment $assessment): array
    {
        return [];
    }
}
