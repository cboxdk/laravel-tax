<?php

declare(strict_types=1);

namespace Cbox\Tax\Concerns;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\AllocationMode;
use Brick\Money\Money;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\RoundingScope;
use Cbox\Tax\Regime\Concerns\AppliesTaxRate;
use Cbox\Tax\ValueObjects\LineAssessment;
use Cbox\Tax\ValueObjects\RateComponent;
use Cbox\Tax\ValueObjects\TaxOrder;

/** Round exact invoice tax once, then distribute minor units to its lines. */
trait RoundsInvoices
{
    use AppliesTaxRate;

    /**
     * @param  list<LineAssessment>  $lines
     * @return list<LineAssessment>
     */
    private function roundInvoice(TaxOrder $order, array $lines): array
    {
        $pricing = [];

        foreach ($order->lines as $line) {
            $pricing[$line->id] = $line->pricing ?? $order->pricing;
        }

        $flat = [];
        $flatPricing = [];
        $positions = [];

        foreach ($lines as $index => $line) {
            $parts = $line->assessment->portions === [] ? [$line->assessment] : $line->assessment->portions;

            foreach ($parts as $partIndex => $part) {
                $id = base64_encode($line->id).':'.$partIndex;
                $positions[$index][] = count($flat);
                $flat[] = new LineAssessment($id, $part);
                $flatPricing[$id] = $pricing[$line->id];
            }
        }

        $flat = $this->roundTaxGroups($this->applySchedules($flat, $flatPricing), $flatPricing);

        foreach ($lines as $index => $line) {
            $parts = array_map(static fn (int $position) => $flat[$position]->assessment, $positions[$index]);

            if ($line->assessment->portions === []) {
                $lines[$index] = new LineAssessment($line->id, $parts[0]);

                continue;
            }

            $net = Money::zero($line->assessment->net->getCurrency(), $line->assessment->net->getContext());
            $tax = $net;
            $gross = $net;

            foreach ($parts as $part) {
                $net = $net->plus($part->net);
                $tax = $tax->plus($part->tax);
                $gross = $gross->plus($part->gross);
            }

            $lines[$index] = new LineAssessment($line->id, $line->assessment->with(
                net: $net,
                tax: $tax,
                gross: $gross,
                breakdown: count($parts) === 1 ? $parts[0]->breakdown : null,
                portions: $parts,
                taxableBase: count($parts) === 1 ? $parts[0]->taxableBase : null,
            ));
        }

        return $lines;
    }

    /**
     * A TABLE TAXES THE SALE, NOT THE LINE. Maryland's and Pennsylvania's tables are
     * read on the sale's taxable price: two lines at $0.40 are one $0.80 sale, taxed 5
     * cents, where the table read line by line gives 3 and 3. So the lines a table
     * prices in one place are summed, the table is read once on the sum, and that tax
     * is shared back across them by their taxable amounts.
     *
     * Only tax-exclusive lines, which is the only way a table is read at all; and only
     * where every line's amount has the same sign, since a table on a sale netted
     * against a return is not a figure either state publishes.
     *
     * @param  list<LineAssessment>  $lines
     * @param  array<string, Pricing>  $pricing
     * @return list<LineAssessment>
     */
    private function applySchedules(array $lines, array $pricing): array
    {
        $groups = [];

        foreach ($lines as $index => $line) {
            $a = $line->assessment;

            if (! $a->isTaxable() || $a->rate?->schedule === null || $a->rate->limitedBy === RateLimit::BracketSchedule || $pricing[$line->id] !== Pricing::Exclusive) {
                continue;
            }

            $key = $a->placeOfSupply->country->value.':'.$a->placeOfSupply->subdivision?->value.':'.$a->rate->percentage->strippedOfTrailingZeros();
            $groups[$key][] = $index;
        }

        foreach ($groups as $indices) {
            if (count($indices) < 2) {
                continue;
            }

            $first = $lines[$indices[0]]->assessment;
            $schedule = $first->rate?->schedule;
            $sale = Money::zero($first->net->getCurrency(), $first->net->getContext());
            $weights = [];
            $signs = [];

            foreach ($indices as $index) {
                $base = $lines[$index]->assessment->taxableBase ?? $lines[$index]->assessment->net;
                $sale = $sale->plus($base);
                $weights[] = $base->getMinorAmount()->abs();
                $signs[$base->isNegative() ? 'negative' : 'positive'] = true;
            }

            $tax = count($signs) > 1 || $sale->isZero() || ! $sale->getContext()->isFixedScale() ? null : $schedule?->taxOn($sale);

            if ($tax === null) {
                continue;
            }

            foreach ($tax->allocate($weights, AllocationMode::FloorToLargestRemainder) as $position => $share) {
                $index = $indices[$position];
                $a = $lines[$index]->assessment;
                $base = $a->taxableBase ?? $a->net;

                $lines[$index] = new LineAssessment($lines[$index]->id, $a->with(
                    tax: $share,
                    gross: $a->net->plus($share),
                    breakdown: $a->rate === null ? null : $this->breakdown($a->rate, $base, $share),
                ));
            }
        }

        return array_values($lines);
    }

    /**
     * @param  list<LineAssessment>  $lines
     * @param  array<string, Pricing>  $pricing
     * @return list<LineAssessment>
     */
    private function roundTaxGroups(array $lines, array $pricing): array
    {
        $groups = [];

        foreach ($lines as $index => $line) {
            $a = $line->assessment;

            if (! $a->isTaxable() || $a->rounding?->scope !== RoundingScope::Invoice || $a->unroundedTax === null || $a->rate?->schedule !== null) {
                continue;
            }

            // Different rates are totalled separately. A mixed delivery contributes
            // its portions to the same groups as the goods those portions accompany.
            $components = array_map(static fn (RateComponent $component): string => $component->level->value.':'.$component->code.':'.$component->percentage->strippedOfTrailingZeros(), $a->rate->components ?? []);
            sort($components);
            $key = $a->placeOfSupply->country->value.':'.$a->placeOfSupply->subdivision?->value
                .':'.$a->rate?->percentage->strippedOfTrailingZeros().':'.$a->rounding->key().':'.implode('|', $components);
            $groups[$key][] = $index;
        }

        foreach ($groups as $indices) {
            $first = $lines[$indices[0]]->assessment;
            $policy = $first->rounding;

            if ($policy === null || $first->unroundedTax === null) {
                continue;
            }

            $factor = 10 ** $policy->places;
            $exact = Money::zero($first->tax->getCurrency())->toRational();
            $units = [];
            $remainders = [];
            $allocated = BigDecimal::zero();

            foreach ($indices as $index) {
                $unrounded = $lines[$index]->assessment->unroundedTax;

                if ($unrounded === null) {
                    continue;
                }

                $exact = $exact->plus($unrounded);
                $scaled = $unrounded->getAmount()->multipliedBy($factor);
                // Toward zero makes a credit the signed reversal of a charge.
                $units[$index] = $scaled->toScale(0, RoundingMode::Down);
                $remainders[$index] = $scaled->minus($units[$index]);
                $allocated = $allocated->plus($units[$index]);
            }

            $target = $policy->round($exact, $first->tax->getContext());
            $difference = $target->getAmount()->multipliedBy($factor)->minus($allocated)->toInt();
            $direction = $difference < 0 ? -1 : 1;
            usort($indices, static fn (int $a, int $b): int => $direction * $remainders[$b]->compareTo($remainders[$a])
                ?: strcmp($lines[$a]->id, $lines[$b]->id));

            foreach ($indices as $position => $index) {
                $a = $lines[$index]->assessment;
                $amount = $units[$index]->plus($position < abs($difference) ? $direction : 0)
                    ->dividedBy($factor, $policy->places);
                $tax = Money::of($amount, $a->tax->getCurrency(), $a->tax->getContext());
                $inclusive = $pricing[$lines[$index]->id] === Pricing::Inclusive;
                $net = $inclusive ? $a->gross->minus($tax) : $a->net;
                $base = $a->taxableBase ?? $a->breakdown?->lines[0]->taxableAmount ?? $a->net;
                $base = $inclusive ? $base->minus($tax->minus($a->tax)) : $base;

                $lines[$index] = new LineAssessment($lines[$index]->id, $a->with(
                    net: $net,
                    tax: $tax,
                    gross: $inclusive ? $a->gross : $net->plus($tax),
                    breakdown: $a->rate === null ? null : $this->breakdown($a->rate, $base, $tax),
                    taxableBase: $base,
                ));
            }
        }

        return array_values($lines);
    }
}
