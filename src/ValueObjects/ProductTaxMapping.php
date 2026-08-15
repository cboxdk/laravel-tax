<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;

/**
 * How one of your products is taxed: the class it falls in, and — where you know it
 * — the classification code that makes the answer exact.
 *
 * TWO FIELDS BECAUSE THE CLASS IS NOT ALWAYS ENOUGH. `TaxClass::Groceries` is the
 * right answer for a carton of milk everywhere, and in half the member states it is
 * not a rate: Hungary taxes foodstuffs at 5% and 18% at once and the class cannot
 * say which. The CN code can, and it belongs here rather than on the invoice line
 * for the same reason the class does — it is a fact about the product, established
 * once, not a decision remade per order.
 *
 * The code is optional and stays optional. Most sellers will never set one, and the
 * class alone is right for most supplies in most countries; the ones who need it
 * are the ones the engine has already told, by flagging the line
 * {@see RateLimit::HeadingAmbiguous} with the remedy attached.
 * That is the intended loop: map coarsely, ship, then refine only what the engine
 * says is worth refining.
 */
readonly class ProductTaxMapping
{
    public function __construct(
        public TaxClass $class,
        /**
         * The product's CN code (goods) or CPA code (services), written however
         * your catalogue holds it — `cn:01022110`, `01022110` or `0102 21 10`. A
         * bare code is read as CN; quote a service as `cpa:…`.
         */
        public ?string $commodityCode = null,
    ) {}
}
