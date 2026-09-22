<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Brick\Money\Context;
use Brick\Money\Context\CustomContext;
use Brick\Money\Money;
use Brick\Money\RationalMoney;
use Cbox\Tax\Enums\RoundingScope;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;

/** Published policy; the arithmetic and invoice allocation implement it. */
final readonly class TaxRounding
{
    /** @var int<0, 8> */
    public int $places;

    public function __construct(
        public RoundingMode $method,
        int $places,
        public RoundingScope $scope,
        /** Null means the publisher makes no local-aggregation assertion. */
        public ?bool $aggregatesLocal = true,
    ) {
        if ($places < 0 || $places > 8 || $aggregatesLocal === false) {
            throw new UnresolvedTaxRule('Unsupported rounding precision or separate local-tax rounding.');
        }

        $this->places = $places;
    }

    public function elected(RoundingScope $scope): self
    {
        if ($scope === RoundingScope::SellerElection) {
            throw new UnresolvedTaxRule('Choose line or invoice rounding for the seller election.');
        }

        return $this->scope === RoundingScope::SellerElection
            ? new self($this->method, $this->places, $scope, $this->aggregatesLocal)
            : $this;
    }

    public function round(RationalMoney $tax, Context $context): Money
    {
        // No second rounding when the host's money context cannot express the
        // published precision. Such a context must refuse instead of changing policy.
        try {
            return $tax->toContext(new CustomContext($this->places), $this->method)
                ->toContext($context, RoundingMode::Unnecessary);
        } catch (RoundingNecessaryException) {
            throw new UnresolvedTaxRule('The money context cannot represent the published tax rounding precision.');
        }
    }

    public function key(): string
    {
        return $this->method->name.':'.$this->places.':'.$this->scope->value.':'.($this->aggregatesLocal === null ? 'unspecified' : 'combined');
    }
}
