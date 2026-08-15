<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * Why a rate is the best available answer rather than the exact one — and what
 * would make it exact.
 *
 * {@see Confidence} says HOW GOOD an answer is. That is enough to decide whether to
 * bill on it and not enough to do anything about it: a caller reading `Derived`
 * learns something is missing but not what, and a warning nobody can act on is a
 * warning everybody filters out.
 *
 * This is the actionable half. Every case names a specific gap and carries the one
 * step that closes it, so an operator reviewing a catalogue can sort by remedy and
 * fix a hundred products with one decision instead of investigating each.
 *
 * Absent (`null`) means the rate is exact for what was asked. That is the common
 * case and it stays cheap: no object, no allocation, nothing to check.
 */
enum RateLimit: string
{
    /**
     * The source rates this heading several ways at once and nothing settled which
     * applies — Hungarian foodstuffs are 5% and 18% simultaneously.
     */
    case HeadingAmbiguous = 'heading_ambiguous';

    /**
     * The state share was returned because nothing resolved the address below the
     * state line. Louisiana's 4.45% against a combined rate reaching 11.45%.
     */
    case NoLocalResolution = 'no_local_resolution';

    /**
     * The line's item code is not in your product catalogue, so it was priced from
     * the fallback class rather than a mapping anybody made.
     *
     * The quietest of these and the one worth surfacing loudest. An unmapped SKU
     * still produces an invoice, at the standard rate, which is right for most
     * products and wrong for exactly the ones a reduced rate exists for.
     */
    case ItemUnmapped = 'item_unmapped';

    /**
     * A band was published for this heading and could not be read. Rare, and it
     * means the published file disagrees with itself.
     */
    case BandUnreadable = 'band_unreadable';

    /** The one step that turns this into an exact answer. */
    public function remedy(): string
    {
        return match ($this) {
            self::HeadingAmbiguous => 'Supply the line\'s CN code (goods) or CPA code (services) as commodityCode; '
                .'the source scopes each competing rate to codes, and most codes resolve to exactly one.',
            self::NoLocalResolution => 'Resolve the address below the state line: enable us_tax_data.rooftop for a '
                .'ZIP+4, or bind a LocalAuthorityResolver for a state the shipped dataset cannot resolve.',
            self::ItemUnmapped => 'Map the item code to a tax class in your ProductCatalogue. '
                .'TaxClass::search() finds the class from the words you already use for the product; '
                .'an empty result means nothing here expresses it, which is itself worth recording.',
            self::BandUnreadable => 'Nothing you can do in your application — the published dataset carries a band '
                .'that is not a rate. Report it against the dataset repository.',
        };
    }

    /**
     * Whether supplying better INPUT closes it.
     *
     * The distinction a review screen needs: an ambiguous heading and an unmapped
     * item are the caller's to fix by classifying the product, an unresolved
     * locality is the operator's to fix by configuration, and an unreadable band is
     * neither — it is ours.
     */
    public function callerCanClose(): bool
    {
        return $this === self::HeadingAmbiguous || $this === self::ItemUnmapped;
    }
}
