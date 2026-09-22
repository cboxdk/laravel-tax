<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * What the law of the place of supply does with a sale the caller says a platform
 * facilitated.
 *
 * Three answers, not two. A deeming rule that reaches only SOME facilitated sales —
 * Article 14a of the VAT Directive reaches an imported consignment worth at most
 * EUR 150, or goods already in the Community sold by a seller established outside it
 * to a customer who is not a taxable person — is not the same fact as one that
 * reaches every sale, and reading the first as the second moves the liability for
 * the tax on a condition nobody checked.
 */
enum MarketplaceLiability: string
{
    /** The law makes the platform the party liable; the seller charges nothing. */
    case PlatformOwes = 'platform_owes';

    /** No deeming rule reaches this place, so the seller's own obligation stands. */
    case SellerCollects = 'seller_collects';

    /**
     * A deeming rule exists and names conditions this reader has not evaluated.
     *
     * The seller's obligation stands — charging is the recoverable direction, and
     * nobody collecting is not — and the assessment says so rather than being
     * stamped authoritative.
     */
    case Conditioned = 'conditioned';
}
