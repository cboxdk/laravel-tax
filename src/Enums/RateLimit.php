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

    /**
     * The rate was found by walking UP to a broader classification than the one
     * supplied, because nothing was published at the code that was asked for.
     *
     * A broader code is an inference, never a published fact, and the register has
     * 95 live cases where a shorter code and a longer one beneath it carry different
     * rates in the same country. Austria taxes food at 10% under CN 04 and carves
     * CN 0401 10 out at 4.9%; answering the chapter rate for the subheading is
     * wrong by more than half.
     */
    case ClassificationInferred = 'classification_inferred';

    /**
     * The jurisdiction levies by a BRACKET SCHEDULE and the rate returned is the
     * per-dollar figure that schedule works out to above one unit.
     *
     * Alabama, Idaho, Maryland and Pennsylvania each publish a table in cents rather
     * than a percentage: 11 to 17 cents is one cent of tax, 18 to 34 is two, and so
     * on, with a per-dollar rate above a dollar. The percentage is exact on whole
     * units and disagrees with the table by up to a cent on the remainder, so it is
     * the right answer for a price and the wrong one for a cent-level reconciliation.
     *
     * It is flagged rather than refused because refusing priced NOTHING in four
     * states, and a rate within a cent — that says so — beats an exception.
     */
    case BracketSchedule = 'bracket_schedule';

    /**
     * The rate was found on a BROADER category than the one asked about, and that
     * rate carries conditions narrowing what it reaches.
     *
     * The register states a condition as prose — the statute's own words, plus a
     * short label — never as a link to a category, so a consumer can read THAT a
     * rate is narrowed and not read what it was narrowed to. The United Kingdom
     * zero-rates food and excludes confectionery and catering from that zero; asked
     * about sweets, the engine climbs to `goods.food`, finds 0%, and returns the
     * exact figure the exclusion exists to deny.
     *
     * The figure is still the best one available — refusing would also break the
     * cases where the exclusion is about something else entirely — so it is returned
     * and marked rather than withheld.
     */
    case ConditionsUnevaluated = 'conditions_unevaluated';

    /**
     * A US SERVICE priced as taxable because the register publishes no rule for it
     * in this state — not because any rule says it is taxable.
     *
     * US states tax goods unless they exempt them and services only where they
     * enumerate them, so for a service "nothing published" leans the other way from
     * "taxable". Medical care, education, financial services and insurance are
     * published for no state at all yet; a doctor's visit in Kansas City was billed
     * at 9.125% and called authoritative. The figure is kept — it is the direction a
     * customer can be refunded from — and marked as the assumption it is.
     */
    case TaxabilityAssumed = 'taxability_assumed';

    /** The one step that turns this into an exact answer. */
    public function remedy(): string
    {
        return match ($this) {
            self::HeadingAmbiguous => 'Supply the line\'s CN code (goods) or CPA code (services) as commodityCode; '
                .'the source scopes each competing rate to codes, and most codes resolve to exactly one.',
            self::NoLocalResolution => 'Resolve the address below the state line: sync the state\'s boundary index '
                .'(`tax:data:sync --state=KS`) so a ZIP+4 expands into its authorities, or bind a '
                .'LocalAuthorityResolver for a state the register cannot resolve.',
            self::ItemUnmapped => 'Map the item code to a tax class in your ProductCatalogue. '
                .'TaxClass::search() finds the class from the words you already use for the product; '
                .'an empty result means nothing here expresses it, which is itself worth recording.',
            self::BandUnreadable => 'Nothing you can do in your application — the published dataset carries a band '
                .'that is not a rate. Report it against the dataset repository.',
            self::ClassificationInferred => 'Supply the code at the length the register publishes it. Codes run to '
                .'two, four, six and eight digits, and a chapter can disagree with a subheading beneath it — '
                .'so `04` is not a safe stand-in for `0401 10`.',
            self::ConditionsUnevaluated => 'Read the conditions on the rate and decide whether this supply is one '
                .'they exclude; each carries the statute\'s own words. Where a jurisdiction\'s exclusions map '
                .'onto tax classes you sell — the UK taxing confectionery and hot food at the standard rate '
                .'while zero-rating groceries — put your own source in front via ChainTaxRateSource.',
            self::TaxabilityAssumed => 'Check whether the state taxes this service: most do not tax medical care, '
                .'education, financial services or insurance. Where it does not, record that for the item — a '
                .'taxability source bound ahead of the register, or a buyer exemption — rather than billing the assumption.',
            self::BracketSchedule => 'Nothing in your application, and nothing is wrong with the figure for a '
                .'price: it is the schedule\'s own per-dollar rate. Reconciling to the cent against a state '
                .'return means applying the published table, which the assessment carries the citation for.',
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
        // ConditionsUnevaluated is deliberately absent: no input the caller can supply
        // settles it. The register would have to publish a rate at the excluded rung,
        // or the host bind a source that does.
        return $this === self::HeadingAmbiguous
            || $this === self::ItemUnmapped
            || $this === self::ClassificationInferred;
    }
}
