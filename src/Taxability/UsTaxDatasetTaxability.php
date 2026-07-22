<?php

declare(strict_types=1);

namespace Cbox\Tax\Taxability;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\UsTaxData\UsTaxDataset;

/**
 * Decides product taxability for US states from the us-tax-data taxability dataset
 * (25 categories, per state), delegating everything else to a fallback matrix:
 *
 *  - A US (state, category) the dataset carries → the dataset's determination.
 *  - A US pair the dataset does NOT carry (e.g. a category left undetermined for a
 *    state), or any non-US jurisdiction → the fallback. The fallback keeps the
 *    engine's defaults: standard tangible goods are taxable, while US digital
 *    services with no determination deny (throw) so an operator must configure them.
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

    public function isTaxable(Jurisdiction $jurisdiction, TaxCategory $category): bool
    {
        if ($jurisdiction->country->value === 'US' && $jurisdiction->subdivision !== null) {
            $determination = $this->dataset->taxability(
                $jurisdiction->subdivision->value,
                $category->datasetCategory(),
            );

            if ($determination !== null) {
                return $determination->taxable;
            }
        }

        return $this->fallback->isTaxable($jurisdiction, $category);
    }
}
