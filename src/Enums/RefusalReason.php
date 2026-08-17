<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * Why there is no answer, and what would produce one.
 *
 * {@see RateLimit} is the sibling and NOT the same question. It rides on a rate that
 * was produced and says what stopped it being exact — a number came back, and here is
 * what is missing from it. This rides on a refusal, where no number came back at all.
 * Collapsing the two would force every case to pretend it was about a rate, and two of
 * these are not: a conditional taxability is about a fact the caller withheld, and an
 * unsupported jurisdiction is about the edge of what this engine claims to know.
 *
 * IT EXISTS BECAUSE PROSE IS NOT ACTIONABLE. Until this enum, a refusal carried only
 * a message. An HTTP layer wanting to tell a shop what to do about a 422 had nothing
 * to switch on — the first attempt recovered a code by searching the message text for
 * a RateLimit value, and it never matched once, because the messages name no enum.
 * A lookup that always falls through is worse than none: it reads as though the codes
 * are wired up.
 *
 * `callerCanClose()` is the important half. Three of these the caller fixes by sending
 * more; two they cannot fix at all, and telling them to try again would be a lie.
 */
enum RefusalReason: string
{
    /**
     * No rate is published for this place and class, and the engine will not assume
     * zero.
     *
     * Ours to fix, not the caller's: either the dataset does not cover the place, or
     * it covers it and the class maps to nothing.
     */
    case RateUnavailable = 'rate_unavailable';

    /**
     * The sources disagree about whether this category is taxed in this state, and
     * the dataset records the disagreement rather than picking.
     *
     * 84 of 95 US (state, category) pairs sat here once and were silently charged in
     * full, because an undetermined taxability had been collapsed to `true`.
     */
    case TaxabilityUndetermined = 'taxability_undetermined';

    /**
     * Taxability turns on a fact nobody supplied. Massachusetts exempts clothing
     * below $175, New York below $110 — the rule is known and the answer is not,
     * until the line's amount reaches the check.
     *
     * The caller closes this one, and it is the most closeable of the five.
     */
    case TaxabilityConditional = 'taxability_conditional';

    /**
     * The place is outside what this engine models. Not a gap in the data — a
     * statement about scope.
     */
    case JurisdictionUnsupported = 'jurisdiction_unsupported';

    /**
     * A threshold is stated in one currency and the supply is in another, with no
     * rate to convert at. Refusing beats converting at a rate nobody chose, because
     * a threshold decides whether tax applies at all — a wrong conversion flips the
     * whole line rather than rounding it.
     */
    case ThresholdCurrencyUnknown = 'threshold_currency_unknown';

    /** The one step that turns this refusal into an answer. */
    public function remedy(): string
    {
        return match ($this) {
            self::RateUnavailable => 'Check the dataset covers this place, and that the tax class maps to a '
                .'published band there. If the place is genuinely outside the dataset, bind a rate source that '
                .'covers it rather than falling back to a rate nobody published.',
            self::TaxabilityUndetermined => 'The published sources disagree here and the dataset says so rather '
                .'than choosing. Decide it yourself and bind a ProductTaxability that answers for this pair, or '
                .'treat the supply as taxable — which over-collects, and over-collection is refundable.',
            self::TaxabilityConditional => 'Send the line amount. Taxability turns on a threshold and the rule is '
                .'known; only the figure is missing.',
            self::JurisdictionUnsupported => 'This place is outside the regimes this engine models. Nothing you '
                .'send will change that — assess it elsewhere, or leave it unassessed and say so on the invoice.',
            self::ThresholdCurrencyUnknown => 'Bill the line in the threshold\'s currency, or supply an exchange '
                .'rate for the supply date. A threshold decides whether tax applies at all, so it is not '
                .'converted at a rate nobody chose.',
        };
    }

    /**
     * Whether the caller can turn this into an answer by sending something different.
     *
     * The distinction decides what a client does with a 422. Where this is true, a
     * shop can fix the request and retry; where it is false, retrying is pointless
     * and the honest response is to fall back and flag the line. Telling a caller to
     * try again on an unsupported jurisdiction would be a lie they would act on.
     */
    public function callerCanClose(): bool
    {
        return match ($this) {
            self::TaxabilityConditional, self::ThresholdCurrencyUnknown => true,
            self::RateUnavailable, self::TaxabilityUndetermined, self::JurisdictionUnsupported => false,
        };
    }
}
