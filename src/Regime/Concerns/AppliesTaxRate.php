<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime\Concerns;

use Brick\Money\Money;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;

/**
 * Shared rate maths for regimes: split an amount into net/tax/gross honouring
 * tax-exclusive vs tax-inclusive pricing, and build a zero amount in the query's
 * currency/context.
 */
trait AppliesTaxRate
{
    /**
     * @return array{Money, Money, Money} net, tax, gross
     */
    protected function split(TaxQuery $query, TaxRate $rate): array
    {
        if ($query->pricing === Pricing::Exclusive) {
            $net = $query->amount;
            $tax = $rate->taxOnNet($net);
            $gross = $net->plus($tax);
        } else {
            $gross = $query->amount;
            $net = $rate->netFromGross($gross);
            $tax = $gross->minus($net);
        }

        return [$net, $tax, $gross];
    }

    protected function zero(TaxQuery $query): Money
    {
        return Money::zero($query->amount->getCurrency(), $query->amount->getContext());
    }
}
