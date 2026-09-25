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
     * The rate carries conditions narrowing what it reaches, and nothing supplied
     * settled them.
     *
     * A category finds the neighbourhood; the conditions decide the house. The United
     * Kingdom zero-rates agricultural inputs — only seeds and live animals of a kind
     * used for food — and zero-rates food except confectionery. Asked about fertiliser
     * or sweets without saying more, the engine finds the zero rate and cannot tell
     * whether this product is one the condition reaches.
     *
     * Most conditions are published typed — as tariff codes, or as predicates over
     * named facts — and settle the moment the product is described: a commodity code
     * answers every one written in codes, and a fact such as `product.isConfectionery`
     * answers the rest. A condition published only as the statute's words cannot be
     * settled by anything the caller sends. Either way the figure is the best one
     * available, so it is returned and marked rather than withheld.
     *
     * A qualifying condition is flagged wherever it is met; an exclusion is flagged only
     * when the answer came from a broader category than the one asked about, because at
     * the exact category it is usually about something else — Ireland's zero rate on
     * books excludes newspapers.
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

    /**
     * A service taxed where it is PERFORMED — a hotel stay, an event, a meal, building
     * work, a journey — priced in the supplier's own country because no performance
     * place was given. Usually right: a German hotel company's hotel is in Germany. A
     * chain with property abroad, an agency selling rooms elsewhere, is not.
     */
    case PerformanceLocationAssumed = 'performance_location_assumed';

    /**
     * Priced at the national rate for a country where the address could still lie in
     * a special territory — Spain (the Canaries, Ceuta, Melilla), Portugal (the
     * Azores, Madeira), Finland (Åland) — because neither a postcode nor a
     * subdivision placed it. A Canary Islands delivery is an export, not 21% VAT.
     */
    case TerritoryUnplaced = 'territory_unplaced';

    /**
     * The law names a different rate for part of this category, and the register
     * declined to file it because it could not say which supplies it covers.
     *
     * Burkina Faso taxes hotel stays at 10% — at APPROVED hotels, which is a fact
     * about the supplier the register has no way to type. Filed against the category
     * it would discount every unapproved hotel in the country, so it is recorded as
     * declined instead, and the category resolves to the standard rate. That rate is
     * right for most sellers and wrong for the ones the law meant, and without this
     * flag nothing told them apart.
     */
    case RateDeclined = 'rate_declined';

    /**
     * The place's law deems a marketplace liable for SOME facilitated sales, and this
     * reader has not evaluated which ones.
     *
     * Article 14a reaches an imported consignment worth at most EUR 150, or goods
     * already in the Community sold by a seller established outside it to a customer
     * who is not a taxable person — 26 member states publish it, each with its own
     * conditions. Treating that as a mandate over every facilitated sale hands the
     * tax to a platform the Directive does not reach; so the seller charges, and this
     * says why that figure may not be the one a platform files.
     */
    case MarketplaceLiabilityUnread = 'marketplace_liability_unread';

    /**
     * Priced from a bare five-digit ZIP that is split between local tax areas, so the
     * rate is one of that ZIP's answers and not necessarily this address's.
     *
     * A ZIP is a mail route, not a tax boundary. Washington's 98001 holds Federal Way
     * and Auburn at 10.4% and unincorporated King County at 10.3%, and from the five
     * digits the store can only return one of them. It used to return it as certain.
     */
    case PostcodeSpansLocalities = 'postcode_spans_localities';

    /**
     * Priced from a postal key in a ZIP that a district drawn over the postal layer
     * reaches, where the district sets a different rate — so the answer is right
     * outside the district and wrong inside it.
     *
     * Nebraska's Good Life Districts set the state's rate at 2.75% inside a boundary
     * no ZIP follows; a ZIP+4 in Elkhorn cannot say whether the address is in Avenue
     * One. Only a point can.
     */
    case DistrictNeedsPoint = 'district_needs_point';

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
            self::ConditionsUnevaluated => 'Describe the product: its CN or HS code as `commodityCode` settles every '
                .'condition written in tariff terms, and the register\'s facts (`product.isConfectionery`, …) on '
                .'`facts` — or once, in the ProductCatalogue — settle the rest. CatalogueAudit lists what each '
                .'product needs in each market. A condition published only as the statute\'s words cannot be '
                .'settled by input; read it and decide.',
            self::TerritoryUnplaced => 'Pass the delivery postcode as `postalCode`, or a subdivision on the place: '
                .'it is what tells the mainland from the Canaries, the Azores, Madeira or Åland.',
            self::PerformanceLocationAssumed => 'Pass where the service is performed as `performedAt` — the hotel, the '
                .'venue, the site. It is taxed there, for a business customer as much as for a consumer.',
            self::TaxabilityAssumed => 'Check whether the state taxes this service: most do not tax medical care, '
                .'education, financial services or insurance. Where it does not, record that for the item — a '
                .'taxability source bound ahead of the register, or a buyer exemption — rather than billing the assumption.',
            self::RateDeclined => 'Check whether this supply is the one the law rates differently: the register '
                .'records the rate it declined for this category, with the statute\'s own words, as a `declined_rate` '
                .'rule on the jurisdiction. Where it applies to what you sell, put your own source in front via '
                .'ChainTaxRateSource.',
            self::MarketplaceLiabilityUnread => 'Decide who collects on this sale: read the place\'s '
                .'`marketplace_facilitator` rule, whose conditions carry the statute\'s own words, and where the '
                .'platform is the deemed supplier bill the sale as facilitated by it. Nothing the caller passes '
                .'settles it yet — the conditions are read but not evaluated.',
            self::PostcodeSpansLocalities => 'Pass the ZIP+4, or geocode the street address: this ZIP is split '
                .'between local tax areas and the five digits cannot say which one the address is in.',
            self::DistrictNeedsPoint => 'Pass the geocoded point with the ZIP+4 (scheme `zip9+latlng`): a district '
                .'drawn over this ZIP sets a different rate inside its boundary, and a postal key cannot say '
                .'whether the address is inside it.',
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
        // ConditionsUnevaluated is closable now that conditions are read: a commodity
        // code or a fact settles every typed one. The few published only as prose are
        // not, and CatalogueAudit says which per product — an enum cannot.
        return in_array($this, [self::HeadingAmbiguous, self::ItemUnmapped, self::ClassificationInferred, self::PerformanceLocationAssumed, self::TerritoryUnplaced, self::ConditionsUnevaluated, self::PostcodeSpansLocalities, self::DistrictNeedsPoint], true);
    }
}
