<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Brick\Math\BigDecimal;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Exceptions\DatasetNotInstalled;
use Cbox\Tax\RateSource\DefersLocalAuthorities;
use Cbox\Tax\Register\Reader\CategoryMap;
use Cbox\Tax\Register\Reader\RateResolver;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\RateComponent;
use Cbox\Tax\ValueObjects\RateProvenance;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * Rates out of the compiled register — the only rate source this package ships.
 *
 * It reads local files. There is no request here and no cache TTL, because there is
 * nothing to expire: the store holds one pinned release, and which release is live
 * changes when somebody runs `tax:data:sync`, not on a timer. A rate cannot move
 * under a half-priced order.
 *
 * WHAT IT REFUSES, AND WHY EACH IS DIFFERENT. Nothing installed is a refusal naming
 * the command that fixes it. A regime the store was not compiled with is a refusal
 * too — a store built `--region=eu` knows nothing about Japan, and answering "no tax
 * in Japan" from an absence would be a fabrication. Only a jurisdiction the store
 * DOES carry and has no rate for returns null, which is the honest "this source
 * cannot answer" the chain is built on.
 */
final readonly class RegisterRateSource implements CommodityRateSource
{
    private const string SOURCE = 'cbox-tax';

    public function __construct(
        private RegisterDataset $dataset,
        private RateResolver $resolver = new RateResolver,
        private LocalAuthorityResolver $authorities = new DefersLocalAuthorities,
    ) {}

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        return $this->rateForCommodity($jurisdiction, $category, null, $at);
    }

    public function rateForCommodity(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        ?string $commodityCode,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        $version = $this->dataset->requireVersion();
        $candidates = $this->candidates($jurisdiction, $at);

        if ($candidates === []) {
            return null;
        }

        $carried = array_values(array_filter($candidates, fn (string $code): bool => $this->dataset->carries($code)));

        if ($carried === []) {
            // Every place this country could be is in a regime nobody compiled. The
            // store cannot answer, and pretending otherwise is the failure this
            // whole design exists to avoid.
            throw new DatasetNotInstalled($this->dataset->storeRoot());
        }

        foreach ($carried as $code) {
            $rate = $this->resolve($code, $category, $commodityCode, $at, $version);

            if ($rate === null) {
                continue;
            }

            return $this->stacked($jurisdiction, $code, $rate, $category, $commodityCode, $at, $version) ?? $rate;
        }

        return null;
    }

    /**
     * Add the local authorities that tax this address to the state share.
     *
     * EVERY AUTHORITY THAT APPLIES, or none of them. Inside Kansas City a county and
     * a city both levy — 6.5 + 1.0 + 1.625 — and a rate that stopped at the first
     * one would be an under-charge stamped authoritative, which is the outcome this
     * package works hardest to prevent. So a local record the store cannot price
     * abandons the whole stack and returns the state share at lower confidence
     * rather than a total that is short by one authority's share.
     *
     * A `combined` record is already an all-in total — California files them that
     * way — so it REPLACES the state share instead of adding to it. Adding it would
     * charge California's own portion twice.
     */
    private function stacked(
        Jurisdiction $jurisdiction,
        string $code,
        TaxRate $state,
        TaxClass $category,
        ?string $commodityCode,
        ?DateTimeImmutable $at,
        string $version,
    ): ?TaxRate {
        // ASKED EVEN WITH NO LOCALITY ON THE JURISDICTION. Colorado is the shape
        // this seam exists for: several authorities at one address, and nothing
        // shipped that can resolve the state below the state line, so there is no
        // locality to carry. Gating the question on one would mean the resolver a
        // host bound for exactly that state is never consulted — and the assessment
        // still comes out with a plausible number, just the state share.
        $authorities = $this->authorities->authoritiesFor($jurisdiction, $at);

        if ($authorities === null) {
            // Nobody resolved below the state line. Whether that is worth FLAGGING
            // depends on what was asked: a caller who supplied an address wanted an
            // address-level answer and did not get one, while a caller who supplied
            // only a state got exactly what they asked for. Flagging both would put
            // a caveat on every state-level assessment, and a caveat on everything
            // is one nobody reads.
            if ($jurisdiction->locality === null) {
                // A US STATE SHARE IS RARELY THE WHOLE RATE — locals apply almost
                // everywhere — so it is Derived and it is FLAGGED. The remedy is
                // real and the operator's to act on: sync the state's boundary
                // index, or bind a resolver. Outside the US a country rate is the
                // whole answer and carries no caveat.
                // ...unless the state HAS no locals to miss. Delaware, Montana, New
                // Hampshire and Oregon levy no sales tax at all, and four more carry
                // no sub-state authority; there the state share is the whole rate and
                // a caveat would send somebody looking for something that is not
                // missing.
                return $jurisdiction->country->value === 'US' && $this->hasLocals($code)
                    ? $this->unstacked($state)
                    : null;
            }

            // Nobody resolved the address below the state line. The state share is
            // the honest answer, and saying so is what lets an operator see the gap.
            return $this->unstacked($state);
        }

        if ($authorities === []) {
            // A POSITIVE FINDING: a row covers this address and no local authority
            // levies there, so the state share is the whole rate. That is a different
            // claim from "we only managed to find the state share", and collapsing
            // the two would either understate a certainty or overstate a guess.
            return new TaxRate(
                $state->percentage,
                $state->kind,
                self::SOURCE,
                Confidence::Authoritative,
                [],
                $state->limitedBy,
                $state->provenance,
            );
        }

        $total = BigDecimal::zero();
        $components = [];

        foreach ($authorities as $authority) {
            // The state is IN the set, and it is the one member whose rate is a
            // standard band rather than a local record.
            $record = $authority === $this->stateOf($authority)
                ? null
                : $this->resolver->local($this->dataset->ratesFor($authority), CategoryMap::keyFor($category), $at);

            if ($record === null) {
                $resolved = $authority === $this->stateOf($authority)
                    ? $state
                    : $this->resolve($authority, $category, $commodityCode, $at, $version);

                if ($resolved === null) {
                    // One authority this store cannot price abandons the WHOLE stack.
                    // A total short by one share is an under-charge stamped
                    // authoritative, and nobody audits a plausible number.
                    return $this->unstacked($state);
                }

                $components[] = new RateComponent($this->levelOf($authority), $resolved->percentage, $authority, $this->nameOf($authority));
                $total = $total->plus($resolved->percentage);

                continue;
            }

            $percentage = $record['percentage'] ?? null;

            if (! is_string($percentage)) {
                return $this->unstacked($state);
            }

            if (($record['kind'] ?? null) === 'combined') {
                // An all-in figure: it IS the whole rate, and adding the state share
                // to it would charge California's own portion twice.
                //
                // It still DECOMPOSES, because a return has to be filed against
                // authorities rather than against a total. The state share is known
                // exactly, so the remainder is the aggregate of every district
                // taxing there — levelled `local`, never attributed to the named
                // city, because the register does not say how that remainder splits
                // and inventing a split would be a filing nobody can defend.
                $all = BigDecimal::of($percentage);
                $local = $all->minus($state->percentage);

                return new TaxRate(
                    $all,
                    $state->kind,
                    self::SOURCE,
                    Confidence::Authoritative,
                    $local->isZero() ? [] : [
                        new RateComponent(JurisdictionLevel::State, $state->percentage, $this->stateOf($authority)),
                        // Trailing zeros stripped: 10.75 − 7.25 is three and a half
                        // per cent, and 3.50 makes a scale artefact of the
                        // subtraction look like a statement about precision.
                        new RateComponent(JurisdictionLevel::Local, $local->strippedOfTrailingZeros(), $authority, $this->shortNameOf($authority)),
                    ],
                    null,
                    $state->provenance,
                );
            }

            $components[] = new RateComponent($this->levelOf($authority), $percentage, $authority, $this->nameOf($authority));
            $total = $total->plus(BigDecimal::of($percentage));
        }

        return new TaxRate(
            // 5.3 + 1.7 is seven per cent. Printing it as 7.0 makes a scale artefact
            // of the addition look like a statement about precision.
            $total->strippedOfTrailingZeros(),
            $state->kind,
            self::SOURCE,
            Confidence::Authoritative,
            $components,
            $state->limitedBy,
            $state->provenance,
        );
    }

    /**
     * The level from the code itself: `us:KS:COUNTY-209` is a county.
     *
     * A bare `us:KS` is the state, which is IN the resolved set — the boundary file
     * says whether the state's own rate applies there, and Nevada's says it does
     * not on every row.
     */
    /**
     * The state share, marked as the product of a resolution that did not complete.
     *
     * Both ways of failing land here and they must look the same to a caller: an
     * address nothing resolved, and an address that resolved to an authority this
     * store cannot price. Either way the figure is short, and the one thing that
     * must not happen is it being returned as though it were the whole rate.
     */
    private function unstacked(TaxRate $state): TaxRate
    {
        return new TaxRate(
            $state->percentage,
            $state->kind,
            self::SOURCE,
            Confidence::Derived,
            [],
            RateLimit::NoLocalResolution,
            $state->provenance,
        );
    }

    /**
     * The authority's published name, for a breakdown somebody has to file from.
     *
     * A remittance line reading `us:FL:COUNTY-ALACHUA` is not one anybody can take
     * to a Department of Revenue.
     */
    private function nameOf(string $code): ?string
    {
        $jurisdiction = $this->dataset->jurisdiction($code);

        return $jurisdiction === null ? null : Shape::text($jurisdiction['name'] ?? null);
    }

    /**
     * The authority's own segment, for a component that names a place rather than a
     * key — `us:CA:CITY-ALAMEDA` reads as `ALAMEDA` on a return.
     */
    private function shortNameOf(string $code): ?string
    {
        $segment = explode(':', $code)[2] ?? null;

        if ($segment === null) {
            return null;
        }

        return str_contains($segment, '-') ? substr($segment, (int) strpos($segment, '-') + 1) : $segment;
    }

    /**
     * Whether the register carries any authority BELOW this state.
     *
     * Read off the jurisdictions the store holds rather than asserted, so a state
     * that adopts a local tax stops being a special case the day the register says
     * so.
     */
    private function hasLocals(string $code): bool
    {
        $parts = explode(':', $code);

        if ($parts[0] !== 'us' || ! isset($parts[1])) {
            return false;
        }

        foreach (array_keys($this->dataset->namesIn('us/'.$parts[1])) as $jurisdiction) {
            if (substr_count($jurisdiction, ':') > 1) {
                return true;
            }
        }

        return false;
    }

    /** The bare state code a local authority sits under. */
    private function stateOf(string $code): string
    {
        $parts = explode(':', $code);

        return $parts[0].':'.($parts[1] ?? '');
    }

    private function levelOf(string $code): JurisdictionLevel
    {
        $parts = explode(':', $code);

        if (count($parts) < 3) {
            return JurisdictionLevel::State;
        }

        return match (strtok($parts[2], '-')) {
            'COUNTY' => JurisdictionLevel::County,
            'CITY' => JurisdictionLevel::City,
            'DISTRICT' => JurisdictionLevel::SpecialDistrict,
            default => JurisdictionLevel::Local,
        };
    }

    /**
     * Where to look, most specific first.
     *
     * A SUB-FEDERAL COUNTRY IS ADDRESSED BY ITS SUBDIVISION, not by itself. The
     * register carries `us:KS`, never a `us:US` — there is no federal sales tax for
     * one to hold — so resolving the United States by country code finds nothing at
     * all, which reads exactly like a country that levies no tax.
     *
     * @return list<string>
     */
    private function candidates(Jurisdiction $jurisdiction, ?DateTimeImmutable $at): array
    {
        $codes = $this->dataset->codesForCountry($jurisdiction->country->value, $at);

        if ($jurisdiction->subdivision === null) {
            return $codes;
        }

        $subdivision = $jurisdiction->subdivision->value;
        $prefix = strtolower(substr($subdivision, 0, 2));
        $regional = $prefix.':'.substr($subdivision, 3);

        return [$regional, ...$codes];
    }

    private function resolve(string $code, TaxClass $category, ?string $commodityCode, ?DateTimeImmutable $at, string $version): ?TaxRate
    {
        $records = $this->dataset->ratesFor($code);

        if ($records === []) {
            return null;
        }

        $resolved = $this->resolver->resolve($records, CategoryMap::keyFor($category), $commodityCode, $at);

        if ($resolved === null) {
            return null;
        }

        $rate = $resolved['rate'];
        $percentage = $rate['percentage'] ?? null;

        if (! is_string($percentage)) {
            // A bracket schedule or a per-unit amount: a real published rate this
            // source cannot express as a percentage. Maryland's tax is a table, and
            // rounding it to six per cent disagrees on 48 of the 100 cent endings.
            return null;
        }

        $effective = $rate['effective'] ?? null;
        $provenance = $rate['provenance'] ?? null;

        $by = $resolved['by'] ?? null;

        return new TaxRate(
            $percentage,
            $this->kind($rate),
            is_string($by) ? self::SOURCE.':'.$by : self::SOURCE,
            $resolved['inferred'] || $resolved['ambiguous'] ? Confidence::Derived : Confidence::Authoritative,
            [],
            $this->limit($resolved),
            new RateProvenance(
                self::SOURCE,
                $version,
                Shape::text(Shape::map($effective)['from'] ?? null),
                Shape::text(Shape::map($provenance)['snapshot'] ?? null),
            ),
        );
    }

    /**
     * The register's band, in the three the engine models.
     *
     * `zero` and `exempt` are separate facts — zero-rating preserves the right to
     * deduct input tax and exemption removes it — but both are 0% to a price, and
     * the engine's {@see RateKind} carries the price side. The distinction survives
     * in the provenance rather than being lost.
     *
     * @param  array<string, mixed>  $rate
     */
    private function kind(array $rate): RateKind
    {
        return match ($rate['kind'] ?? null) {
            'standard', 'combined' => RateKind::Standard,
            'zero', 'exempt' => RateKind::Zero,
            default => RateKind::Reduced,
        };
    }

    /**
     * @param  array{rate: array<string, mixed>, inferred: bool, ambiguous: bool}  $resolved
     */
    private function limit(array $resolved): ?RateLimit
    {
        if ($resolved['ambiguous']) {
            return RateLimit::HeadingAmbiguous;
        }

        return $resolved['inferred'] ? RateLimit::ClassificationInferred : null;
    }
}
