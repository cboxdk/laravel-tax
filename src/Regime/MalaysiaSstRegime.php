<?php

declare(strict_types=1);

namespace Cbox\Tax\Regime;

use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Contracts\TaxRegime;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\UnresolvedTaxRate;
use Cbox\Tax\Regime\Concerns\AppliesTaxRate;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * Malaysia Sales & Service Tax (SST) — deliberately NOT a destination VAT. A
 * registered foreign digital-service provider charges Malaysian service tax on
 * **both B2C and B2B** supplies, with **no reverse-charge carve-out**. So this
 * regime always applies the service-tax rate; it never reverse-charges, which is
 * exactly why Malaysia cannot use the generic national regime.
 */
readonly class MalaysiaSstRegime implements TaxRegime
{
    use AppliesTaxRate;

    public function assess(TaxQuery $query, TaxRateSource $rates): TaxAssessment
    {
        $rate = $rates->rateFor($query->place, $query->category);

        if ($rate === null) {
            throw UnresolvedTaxRate::for($query->place);
        }

        [$net, $tax, $gross] = $this->split($query, $rate);

        return new TaxAssessment(
            treatment: TaxTreatment::Standard,
            net: $net,
            tax: $tax,
            gross: $gross,
            placeOfSupply: $query->place,
            rate: $rate,
            reason: sprintf('Malaysia SST: service tax at %s%% (charged on B2B and B2C; no reverse charge).', $rate->percentage),
        );
    }
}
