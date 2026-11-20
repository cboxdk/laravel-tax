<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * One state's margin scheme for one class of goods and one method, as the register
 * publishes it.
 *
 * Three of these the Directive leaves to the state, so none is assumed. `rate` is
 * which rate the margin bears: the goods' own (`goods`) or the standard rate whatever
 * the goods are (`standard`, Germany's § 25a Abs. 5). `export` is what leaving the tax
 * territory does: `exempt` in the Union (Art. 321), `taxed` in the United Kingdom,
 * which withdrew the zero rate for margin goods on 1 May 2023. `mention` is the
 * wording the invoice carries. Null means the installed release does not say, and the
 * engine refuses rather than picking.
 */
readonly class MarginSchemeRule
{
    /**
     * @param  list<string>  $excludedCodes  Tariff headings the state shuts out of the scheme.
     */
    public function __construct(
        public string $jurisdiction,
        public ?string $rate = null,
        public ?string $export = null,
        public ?InvoiceMention $mention = null,
        public array $excludedCodes = [],
        /** The most a purchase may have cost under this method, with its currency, where the state caps it. */
        public ?string $purchasePriceLimit = null,
        public ?string $purchasePriceLimitCurrency = null,
        public ?string $source = null,
        /** The day the export treatment holds from, where the publisher dates it; before it the rule states none. */
        public ?string $exportFrom = null,
    ) {}
}
