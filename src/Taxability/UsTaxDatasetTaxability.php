<?php

declare(strict_types=1);

namespace Cbox\Tax\Taxability;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Enums\TaxabilityTreatment;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\ThresholdRule;
use Cbox\Tax\Exceptions\UnresolvedProductTaxability;
use Cbox\Tax\UsTaxData\TaxabilityDetermination;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\TaxDetermination;
use DateTimeImmutable;

/**
 * Decides product taxability for US states from the us-tax-data taxability dataset
 * (25 categories, per state), delegating everything else to a fallback matrix:
 *
 *  - A US (state, category) the dataset carries → the dataset's determination.
 *  - A US pair the dataset does NOT carry (e.g. a category left undetermined for a
 *    state), or any non-US jurisdiction → the fallback. The fallback keeps the
 *    engine's defaults: standard tangible goods are taxable, while every other US
 *    category with no determination denies (throws) so an operator must configure
 *    it. The dataset leaves those pairs out deliberately — because its sources
 *    disagree — and inheriting that as "taxable" would turn a documented gap into
 *    a silent over-collection.
 *
 * A determination whose treatment is CONDITIONAL also denies: see below.
 *
 * This replaces the hand-curated US SaaS list as the default US taxability source,
 * while leaving the rest of the world on the fallback matrix.
 */
readonly class UsTaxDatasetTaxability implements ProductTaxability
{
    public function __construct(
        private UsTaxDataset $dataset,
        private ProductTaxability $fallback,
    ) {}

    public function determine(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        Money $amount,
        ?DateTimeImmutable $at = null,
    ): TaxDetermination {
        $key = $category->datasetCategory();

        // A class with no US category is not a lookup that can be attempted. US
        // lodging is taxed by separate transient-occupancy regimes at city level,
        // and passenger transport and utility supply sit outside general sales tax
        // in many states — so the dataset carries no determination, and the
        // fallback refuses rather than charging general sales tax on a supply that
        // owes a different tax to a different authority.
        if ($key !== null && $jurisdiction->country->value === 'US' && $jurisdiction->subdivision !== null) {
            $determination = $this->dataset->taxability(
                $jurisdiction->subdivision->value,
                $key,
                $at,
            );

            if ($determination !== null) {
                return $this->translate($jurisdiction, $category, $determination);
            }
        }

        return $this->fallback->determine($jurisdiction, $category, $amount, $at);
    }

    /**
     * Turn the dataset's determination into the engine's.
     *
     * The conditional case is the one this seam was rebuilt for. Massachusetts
     * exempts clothing below $175, New York below $110, Rhode Island below $250 —
     * and the mechanics differ, so the dataset carries the threshold AND how it
     * applies. A rule that names a threshold without saying which is refused
     * rather than guessed: reading a New York cliff as Massachusetts excess-only
     * would under-collect on every garment over $110 in the state.
     */
    private function translate(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        TaxabilityDetermination $determination,
    ): TaxDetermination {
        $conditions = $determination->conditions ?? [];

        if ($determination->treatment === TaxabilityTreatment::Conditional) {
            $threshold = $conditions['exemptBelowCents'] ?? null;
            $rule = $conditions['thresholdRule'] ?? null;
            $rule = is_string($rule) ? ThresholdRule::tryFrom($rule) : null;

            if (! is_int($threshold) || $rule === null) {
                throw UnresolvedProductTaxability::conditional($jurisdiction, $category);
            }

            // USD, always: this dataset publishes US state law, and every price
            // threshold in it is a dollar figure from a statute.
            return TaxDetermination::belowThreshold($threshold, $rule, 'USD');
        }

        if ($determination->treatment === TaxabilityTreatment::ReducedRate) {
            $rate = $conditions['rate'] ?? null;

            // The dataset states these as a fraction (0.04), the engine as a
            // percentage ("4"). Getting that wrong is a hundredfold error, so an
            // unusable value refuses rather than being coerced.
            if (! is_float($rate) && ! is_int($rate)) {
                throw UnresolvedProductTaxability::conditional($jurisdiction, $category);
            }

            return TaxDetermination::reducedAt((string) BigDecimal::of((string) $rate)->multipliedBy(100));
        }

        return $determination->taxable ? TaxDetermination::taxable() : TaxDetermination::exempt();
    }
}
