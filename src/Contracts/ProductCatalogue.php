<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\ValueObjects\ProductTaxMapping;

/**
 * Resolves your own item code — a SKU, a plan id, a product slug — to how it is
 * taxed.
 *
 * WHY THE MAPPING BELONGS TO THE PRODUCT AND NOT THE INVOICE LINE. Deciding a tax
 * class while building an invoice means deciding it again on every invoice, in
 * whatever code path happens to be writing that line, with no record of the
 * decision and nothing to review. Ten thousand SKUs become ten thousand chances to
 * pick differently. Registered against the item once, it is a fact about the
 * product that a person can be asked about, an import can populate, and an audit
 * can inspect.
 *
 * RESOLUTION IS THREE-DEEP, most specific first:
 *
 *  1. A class stated on the query itself — an override for the line in hand.
 *  2. This catalogue, keyed by the item code the query carries.
 *  3. The configured fallback class, for a code nothing has mapped yet.
 *
 * THE THIRD IS WHERE ENGINES QUIETLY GO WRONG, and it is the reason this contract
 * exists rather than a plain array lookup. An unmapped SKU still has to produce an
 * invoice, so it gets the fallback — and then nothing says it did. The line is
 * taxed at the standard rate, which is correct for most products and wrong for
 * exactly the ones a reduced rate exists for, and nobody finds out until a customer
 * or an auditor does. So an unmapped code is REPORTED: the assessment carries
 * {@see RateLimit::ItemUnmapped}, and a review can list every SKU
 * that has never been classified.
 *
 * Null means "I do not know this code", not "this code is untaxed". A catalogue
 * that cannot reach its own storage must return null and let the fallback apply
 * rather than answering from an empty table.
 */
interface ProductCatalogue
{
    /**
     * How an item code is taxed, or null when the catalogue does not carry it.
     */
    public function find(string $itemCode): ?ProductTaxMapping;
}
