<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Tax\Enums\MarginGoods;
use Cbox\Tax\Enums\MarginMethod;
use InvalidArgumentException;

/**
 * The seller's election to tax one supply under the margin scheme (Arts. 311–325 of
 * the VAT Directive; VATA 1994 s. 50A in the United Kingdom).
 *
 * ONLY THE SELLER KNOWS THIS, which is why the register's `margin_scheme` rules sat
 * unapplied: the scheme is an election per supply (Art. 319 lets the dealer use the
 * normal rules for any of them), it needs what the dealer paid, and it is open only
 * to goods bought without deductible VAT — from a private person, an exempt seller or
 * another dealer under the scheme. Stating this value IS the election and the
 * assertion that the purchase qualified; the engine checks what it can see (goods,
 * the state's published scheme, its exclusions) and takes the rest from the seller,
 * as it takes a marketplace assertion.
 *
 * `purchasePrice` is everything the dealer paid for the item, in the currency of the
 * sale. The query's amount is the selling price: everything the customer pays, tax
 * included, whatever the document's pricing says — a margin sale has no tax-exclusive
 * price, because the tax is inside a figure the customer never sees.
 */
readonly class MarginSale
{
    public function __construct(
        public Money $purchasePrice,
        public MarginGoods $goods = MarginGoods::SecondHandGoods,
        public MarginMethod $method = MarginMethod::Individual,
    ) {
        if ($purchasePrice->isNegative()) {
            throw new InvalidArgumentException('A purchase price is what the dealer paid, zero or more.');
        }
    }

    /**
     * The same election for a charge that accompanies the sale: delivery billed to the
     * customer is part of the selling price and has no purchase price of its own.
     */
    public function forAccompanyingCharge(Money $charge): self
    {
        return new self(Money::zero($charge->getCurrency(), $charge->getContext()), $this->goods, $this->method);
    }
}
