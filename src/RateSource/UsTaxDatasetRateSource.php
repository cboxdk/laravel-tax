<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Brick\Math\BigDecimal;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateBasis;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxabilityTreatment;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * Resolves US sales-tax rates from the us-tax-data dataset. It answers ONLY for US
 * jurisdictions (returning null otherwise, so a composed {@see ChainTaxRateSource}
 * falls through to the other sources for the rest of the world), and resolves at
 * three levels of precision:
 *
 *  1. A category with a `reduced_rate` treatment (e.g. grocery at 2%) returns that
 *     reduced rate directly — a category rule, applied whatever the location.
 *  2. When the jurisdiction carries a rooftop {@see LocalityCode},
 *     the state and the matched local record are stacked into an all-in rate
 *     (respecting the state's {@see RateBasis}) at {@see Confidence::Authoritative}.
 *  3. Otherwise the authoritative STATE rate is returned at {@see Confidence::Derived}
 *     — honest that it is the state share, not a rooftop all-in figure.
 *
 * Deny-by-default throughout: an unknown state, a no-sales-tax state, or an
 * unavailable dataset yields null, and the engine denies rather than assuming 0%.
 */
readonly class UsTaxDatasetRateSource implements TaxRateSource
{
    private const string SOURCE = 'us-tax-data';

    public function __construct(private UsTaxDataset $dataset) {}

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxCategory $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        if ($jurisdiction->country->value !== 'US' || $jurisdiction->subdivision === null) {
            return null;
        }

        $state = $jurisdiction->subdivision->value;

        // 1. A reduced-rate category rule wins over the general rate, wherever the
        //    supply is: it is a product rule, not a location one.
        $reduced = $this->reducedRate($state, $category);

        if ($reduced !== null) {
            return $reduced;
        }

        // 2. Rooftop all-in when a locality was resolved; else 3. the state rate.
        $locality = $jurisdiction->locality;

        if ($locality !== null && $locality->subdivision->value === $state) {
            $stacked = $this->stacked($state, $locality->value, $at);

            if ($stacked !== null) {
                return $stacked;
            }
        }

        return $this->stateRate($state);
    }

    /**
     * A category carrying a `reduced_rate` treatment with a numeric `rate`
     * condition — returned as a reduced-kind rate. Null otherwise.
     */
    private function reducedRate(string $state, TaxCategory $category): ?TaxRate
    {
        $determination = $this->dataset->taxability($state, $category->datasetCategory());

        if ($determination === null || $determination->treatment !== TaxabilityTreatment::ReducedRate) {
            return null;
        }

        $percent = $this->fractionToPercent($determination->conditions['rate'] ?? null);

        if ($percent === null) {
            return null;
        }

        return new TaxRate($percent, RateKind::Reduced, self::SOURCE, Confidence::Authoritative);
    }

    /**
     * The all-in rate for a resolved rooftop locality: the matched local record
     * stacked onto the state share per the state's {@see RateBasis}. Null when no
     * active local record is carried for the code (the caller then falls back to
     * the state rate).
     */
    private function stacked(string $state, string $code, ?DateTimeImmutable $at): ?TaxRate
    {
        $local = $this->activeGeneralRate($this->dataset->localRateRecords($state, $code), $at);

        if ($local === null) {
            return null;
        }

        $localPercent = BigDecimal::of($local)->multipliedBy(100);

        // Combined records already include the state share; component records are
        // just the local addend and need the state rate added on top.
        if ($this->dataset->rateBasis($state) === RateBasis::Component) {
            $statePercent = $this->dataset->stateRatePercent($state);

            if ($statePercent !== null) {
                $localPercent = $localPercent->plus(BigDecimal::of($statePercent));
            }
        }

        return new TaxRate(
            UsTaxDataset::normalize((string) $localPercent),
            RateKind::Standard,
            self::SOURCE,
            Confidence::Authoritative,
        );
    }

    private function stateRate(string $state): ?TaxRate
    {
        $percent = $this->dataset->stateRatePercent($state);

        if ($percent === null) {
            return null;
        }

        return new TaxRate($percent, RateKind::Standard, self::SOURCE, Confidence::Derived);
    }

    /**
     * The `generalRate` (as a fraction string) of the record whose effective window
     * is open (effectiveTo null) or covers `$at`, from a locality's record list.
     *
     * @param  list<array<array-key, mixed>>  $records
     */
    private function activeGeneralRate(array $records, ?DateTimeImmutable $at): ?string
    {
        $fallback = null;

        foreach ($records as $record) {
            $rate = $record['generalRate'] ?? null;

            if (! is_int($rate) && ! is_float($rate)) {
                continue;
            }

            $fallback ??= (string) $rate;

            $from = is_string($record['effectiveFrom'] ?? null) ? $record['effectiveFrom'] : null;
            $to = is_string($record['effectiveTo'] ?? null) ? $record['effectiveTo'] : null;

            if ($this->covers($from, $to, $at)) {
                return (string) $rate;
            }
        }

        // No window matched explicitly — use the first well-formed record.
        return $fallback;
    }

    /**
     * Whether an effective window [from, to] covers the query date. A null `$at`
     * means "current": only an open-ended record (no effectiveTo) qualifies.
     */
    private function covers(?string $from, ?string $to, ?DateTimeImmutable $at): bool
    {
        if ($at === null) {
            return $to === null;
        }

        $date = $at->format('Y-m-d');

        return ($from === null || $from <= $date) && ($to === null || $date <= $to);
    }

    private function fractionToPercent(mixed $fraction): ?string
    {
        if (is_int($fraction) || is_float($fraction)) {
            $fraction = (string) $fraction;
        }

        if (! is_string($fraction) || ! is_numeric($fraction)) {
            return null;
        }

        return UsTaxDataset::normalize((string) BigDecimal::of($fraction)->multipliedBy(100));
    }
}
