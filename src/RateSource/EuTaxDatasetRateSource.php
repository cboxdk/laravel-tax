<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\EuTaxData\EuTaxDataset;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * Reads EU VAT rates from the published `cboxdk/eu-tax-dataset`.
 *
 * The Commission publishes no export — its SOAP service and web UI are the only
 * sources — so this reads the compiled dataset built from that service, with
 * per-band provenance and a dated series back to the start of its records.
 *
 * WHAT THIS GIVES OVER CALLING TEDB DIRECTLY. The service answers only about a
 * date you name and cannot be asked what a rate WAS in any usable way per request;
 * the dataset carries the answer already resolved, so a 2024 supply is priced with
 * the 2024 rate without a round trip. And where TEDB rates one heading several ways
 * at once, the dataset either publishes a cited determination or publishes the
 * ambiguity — which is the thing a calculation engine must not paper over.
 *
 * THREE OUTCOMES, and the third is why this class is careful:
 *
 *  - The class maps to a heading with a band → that rate, `Authoritative`.
 *  - The class maps to nothing, or to a heading the state does not rate → the
 *    standard rate, `Authoritative`. Most supplies land here and it is correct.
 *  - The heading is published as UNDECIDED → the standard rate, but at
 *    {@see Confidence::Derived}. The source rates it several ways and nothing
 *    resolved which applies; the standard rate is the safe fallback and
 *    over-charging is recoverable, but a caller billing on it should be able to see
 *    that a better answer exists before it does.
 */
readonly class EuTaxDatasetRateSource implements TaxRateSource
{
    public function __construct(private EuTaxDataset $dataset) {}

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        $country = $jurisdiction->country->value;
        $window = $this->dataset->window($country, $at);

        if ($window === null) {
            // No country, or a date before the records begin. Denying is the point:
            // the engine refuses rather than assuming 0%.
            return null;
        }

        foreach ($this->dataset->headingsFor($category->value) as $heading) {
            $band = $window['bands'][$heading] ?? null;
            $rate = is_array($band) ? ($band['rate'] ?? null) : null;

            if (is_array($band) && is_string($rate)) {
                /** @var array<string, mixed> $band */
                return new TaxRate(
                    $rate,
                    RateKind::Reduced,
                    $this->source($country, $heading, $band),
                    Confidence::Authoritative,
                );
            }

            // Undecided beats the remaining headings: the state DOES rate this
            // heading, several ways, and nothing resolved which. Trying the next
            // heading would quietly answer a different question — France's books
            // under LOAN_LIBRARIES when BOOKS is the ambiguous one.
            if (isset($window['undecided'][$heading])) {
                return new TaxRate(
                    $window['standard'],
                    RateKind::Standard,
                    sprintf('eu-tax-dataset (%s %s undecided, standard rate applied)', $country, $heading),
                    Confidence::Derived,
                );
            }
        }

        return new TaxRate(
            $window['standard'],
            RateKind::Standard,
            'eu-tax-dataset ('.$country.' standard)',
            Confidence::Authoritative,
        );
    }

    /**
     * The provenance string, carrying HOW the band was arrived at.
     *
     * A `determination` band means somebody chose between rates TEDB carried at
     * once, and the dataset publishes the grounds. Saying so here is what lets an
     * operator reading an assessment check the call rather than take it.
     *
     * @param  array<string, mixed>  $band
     */
    private function source(string $country, string $heading, array $band): string
    {
        $basis = is_string($band['basis'] ?? null) ? $band['basis'] : 'source';

        return $basis === 'determination'
            ? sprintf('eu-tax-dataset (%s %s, determined)', $country, $heading)
            : sprintf('eu-tax-dataset (%s %s)', $country, $heading);
    }
}
