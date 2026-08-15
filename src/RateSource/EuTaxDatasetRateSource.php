<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\EuTaxData\EuTaxDataset;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;
use Throwable;

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
readonly class EuTaxDatasetRateSource implements CommodityRateSource
{
    public function __construct(private EuTaxDataset $dataset) {}

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        return $this->rateForCommodity($jurisdiction, $category, null, $at);
    }

    /**
     * The rate, refined by the supply's classification code where one is given.
     *
     * THIS IS WHERE THE UNDECIDED HEADINGS GET ANSWERED. A heading the publisher
     * could not settle carries the CN/CPA codes the source scopes each competing
     * rate to, and 1,901 of the 2,028 such codes are claimed by exactly one rate.
     * Austria rates foodstuffs at 4.9%, 10% and 13% at once and the heading says
     * nothing about which; `cn:04011010` — milk under 1% fat — says 4.9%.
     *
     * A code REFINES and never restricts. Unknown, contested, or for a heading that
     * was already settled, it changes nothing and the class alone decides exactly as
     * before. That is what lets a caller pass a code opportunistically without
     * having to know whether this country needed one.
     */
    public function rateForCommodity(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?string $commodityCode,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        $country = $jurisdiction->country->value;
        $window = $this->dataset->window($country, $at);

        if ($window === null) {
            // No country, or a date before the records begin. Denying is the point:
            // the engine refuses rather than assuming 0%.
            return null;
        }

        $code = $this->normalizeCode($commodityCode);

        foreach ($this->dataset->headingsFor($category->value) as $heading) {
            $band = $window['bands'][$heading] ?? null;
            $rate = is_array($band) ? ($band['rate'] ?? null) : null;

            if (is_array($band) && is_string($rate)) {
                /** @var array<string, mixed> $band */
                try {
                    return new TaxRate(
                        $rate,
                        RateKind::Reduced,
                        $this->source($country, $heading, $band),
                        Confidence::Authoritative,
                    );
                } catch (Throwable) {
                    // A band that is not a rate. The publisher refuses to emit one,
                    // so reaching here means verification passed and something else
                    // went wrong — and the blast radius of throwing is the whole
                    // engine, for every country, over one bad heading in one.
                    //
                    // Priced at the standard rate instead: over-charging is
                    // recoverable, a failed checkout is not, and the confidence and
                    // source say plainly that a band was there and could not be read.
                    // Silently trying the next heading would be the quiet
                    // substitution this file exists to avoid.
                    return $this->standard(
                        $window['standard'],
                        sprintf('eu-tax-dataset (%s %s unreadable, standard rate applied)', $country, $heading),
                        Confidence::LowConfidence,
                    );
                }
            }

            // Undecided beats the remaining headings: the state DOES rate this
            // heading, several ways, and nothing resolved which. Trying the next
            // heading would quietly answer a different question — France's books
            // under LOAN_LIBRARIES when BOOKS is the ambiguous one.
            if (isset($window['undecided'][$heading])) {
                // Unless the supply's own classification settles it. The publisher
                // could not choose for the heading; the code chooses for THIS
                // supply, on the source's own scoping rather than anyone's reading
                // of it.
                $scoped = $code === null ? null : $this->scopedRate($window, $heading, $code);

                if ($scoped !== null) {
                    try {
                        return new TaxRate(
                            $scoped,
                            RateKind::Reduced,
                            sprintf('eu-tax-dataset (%s %s, %s)', $country, $heading, $code),
                            Confidence::Authoritative,
                        );
                    } catch (Throwable) {
                        // Same reasoning as an unreadable band: priced at the
                        // standard rate rather than throwing out every assessment
                        // for every country over one bad scope entry.
                        return $this->standard(
                            $window['standard'],
                            sprintf('eu-tax-dataset (%s %s scope unreadable, standard rate applied)', $country, $heading),
                            Confidence::LowConfidence,
                        );
                    }
                }

                return $this->standard(
                    $window['standard'],
                    sprintf('eu-tax-dataset (%s %s undecided, standard rate applied)', $country, $heading),
                    Confidence::Derived,
                );
            }
        }

        return $this->standard(
            $window['standard'],
            'eu-tax-dataset ('.$country.' standard)',
            Confidence::Authoritative,
        );
    }

    /**
     * A commodity code in the form the dataset keys its scopes by: scheme-prefixed,
     * lowercased, with the spaces the tariff prints for readability removed.
     *
     * A caller quoting `0201 30 00` and one quoting `cn:02013000` mean the same
     * heading and must reach the same rate. A bare code with no scheme is assumed
     * to be a **CN** code, because that is what a seller of goods has to hand; a
     * service is quoted `cpa:…` explicitly. Guessing the scheme from the digits is
     * not possible — `32` is a valid CN chapter and a valid CPA division — so the
     * default is stated rather than inferred.
     */
    private function normalizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $normalized = strtolower((string) preg_replace('/\s+/', '', $code));

        if ($normalized === '') {
            return null;
        }

        return str_contains($normalized, ':') ? $normalized : 'cn:'.$normalized;
    }

    /**
     * The rate the scoped codes give for a heading, by LONGEST PREFIX.
     *
     * Longest wins because that is how tariff classification works: the source
     * scopes Austria's 10% to the bare chapter `cn:02` and other rates to full
     * eight-digit codes, so a specific code has to beat the chapter containing it.
     * Null when nothing matches, which leaves the honest standard-rate fallback.
     *
     * @param  array{standard: string, bands: array<array-key, mixed>, undecided: array<array-key, mixed>, scopes: array<array-key, mixed>}  $window
     */
    private function scopedRate(array $window, string $heading, string $code): ?string
    {
        $scoped = $window['scopes'][$heading] ?? null;

        if (! is_array($scoped)) {
            return null;
        }

        $best = null;
        $bestLength = -1;

        foreach ($scoped as $prefix => $rate) {
            if (! is_string($prefix) || ! is_string($rate)) {
                continue;
            }

            if (strlen($prefix) > $bestLength && str_starts_with($code, $prefix)) {
                $best = $rate;
                $bestLength = strlen($prefix);
            }
        }

        return $best;
    }

    /**
     * The member state's standard rate, or null if even that will not read.
     *
     * A corrupt BAND can be worked around by charging the standard rate. A corrupt
     * standard rate cannot be worked around at all — there is nothing left to fall
     * back to — so the source denies and the engine refuses the line rather than
     * inventing a percentage.
     */
    private function standard(string $percentage, string $source, Confidence $confidence): ?TaxRate
    {
        try {
            return new TaxRate($percentage, RateKind::Standard, $source, $confidence);
        } catch (Throwable) {
            return null;
        }
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
