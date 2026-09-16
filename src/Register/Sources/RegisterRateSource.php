<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CommodityRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Exceptions\DatasetNotInstalled;
use Cbox\Tax\Register\Reader\CategoryMap;
use Cbox\Tax\Register\Reader\RateResolver;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
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
        $candidates = $this->dataset->codesForCountry($jurisdiction->country->value, $at);

        if ($candidates === []) {
            return null;
        }

        $carried = array_values(array_filter($candidates, fn (string $code): bool => $this->dataset->carries($code)));

        if ($carried === []) {
            // Every place this country could be is in a regime nobody compiled. The
            // store cannot answer, and pretending otherwise is the failure this
            // whole design exists to avoid.
            throw new DatasetNotInstalled(Shape::text($this->dataset->manifest()['version'] ?? null) ?? $version);
        }

        foreach ($carried as $code) {
            $rate = $this->resolve($code, $category, $commodityCode, $at, $version);

            if ($rate !== null) {
                return $rate;
            }
        }

        return null;
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

        return new TaxRate(
            $percentage,
            $this->kind($rate),
            self::SOURCE,
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
