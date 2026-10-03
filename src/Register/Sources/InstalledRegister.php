<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\TaxRegister;
use Cbox\Tax\Enums\LocalResolution;
use Cbox\Tax\Enums\ThresholdOperator;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\ValueObjects\DistanceSalesThreshold;
use Cbox\Tax\ValueObjects\RateRecord;
use Cbox\Tax\ValueObjects\RegisterCategory;
use Cbox\Tax\ValueObjects\RegisterRelease;
use DateTimeImmutable;
use Throwable;

/**
 * The installed register, as an application may ask it — typed at this edge, so
 * nothing past it reads the store's JSON.
 *
 * Reads through the same dataset the engine prices with, so the release it reports is
 * the one a calculation in the same request uses, not whatever the pointer says a
 * moment later.
 */
readonly class InstalledRegister implements TaxRegister
{
    public function __construct(
        private RegisterDataset $dataset,
        private StoreLayout $layout,
        private StorePointer $pointer,
    ) {}

    public function release(): ?RegisterRelease
    {
        $version = $this->dataset->version();

        if ($version === null) {
            return null;
        }

        $manifest = $this->dataset->manifest();

        return new RegisterRelease(
            version: $version,
            schemaVersion: Shape::text($manifest['schemaVersion'] ?? null) ?? 'unknown',
            publishedAt: $this->date($manifest['publishedAt'] ?? null),
            compiledAt: $this->date($manifest['compiledAt'] ?? null),
            contentHash: Shape::text($manifest['contentHash'] ?? null),
        );
    }

    public function categories(): array
    {
        $categories = [];

        foreach ($this->dataset->categories() as $key => $category) {
            $categories[] = new RegisterCategory(
                $key,
                Shape::text($category['name'] ?? null) ?? $key,
                Shape::text($category['parent'] ?? null),
                Shape::text($category['description'] ?? null),
                array_values(array_filter(is_array($category['regions'] ?? null) ? $category['regions'] : [], is_string(...))),
                Shape::text($category['cites'] ?? null),
            );
        }

        return $categories;
    }

    public function assertCategoryPublished(string $key): void
    {
        $this->dataset->assertCategoryPublished($key);
    }

    public function localResolution(string $state): ?LocalResolution
    {
        $needs = $this->dataset->usLocalResolution(strtoupper($state));

        return $needs === null ? null : LocalResolution::tryFrom($needs);
    }

    public function distanceSalesThreshold(?DateTimeImmutable $at = null): ?DistanceSalesThreshold
    {
        $rule = $this->dataset->rulesOn('eu', 'threshold', $at ?? new DateTimeImmutable('today'))[0] ?? null;
        $payload = Shape::map(Shape::map($rule)['payload'] ?? null);
        $amount = null;
        $operator = null;
        $measuredOver = [];

        // One amount, measured over each period on its own: the Directive's €10,000
        // in the preceding calendar year OR the current one. A limb stating another
        // amount is not the same threshold, and is not folded in.
        foreach (Shape::records($payload['conditions'] ?? null) as $limb) {
            $figure = Shape::text($limb['amount'] ?? null);
            $currency = Shape::text($limb['currency'] ?? null);

            if ($figure === null || $currency === null) {
                continue;
            }

            try {
                $limbAmount = Money::of($figure, $currency);
            } catch (Throwable) {
                continue;
            }

            if ($amount !== null && ! $limbAmount->isEqualTo($amount)) {
                continue;
            }

            $amount = $limbAmount;
            $operator ??= ThresholdOperator::tryFrom(Shape::text($limb['amountOperator'] ?? null) ?? '');
            $period = Shape::text($limb['measuredOver'] ?? null);

            if ($period !== null) {
                $measuredOver[] = $period;
            }
        }

        return $amount === null ? null : new DistanceSalesThreshold($amount, $operator, $measuredOver);
    }

    public function rateRecords(Jurisdiction $place, ?string $release = null): array
    {
        $dataset = $release === null ? $this->dataset : new RegisterDataset($this->layout, $this->pointer, $release);
        $dataset->requireVersion();

        $codes = $place->subdivision === null
            ? $dataset->codesForCountry($place->country->value)
            : [strtolower($place->country->value).':'.substr($place->subdivision->value, 3)];

        $records = [];

        foreach ($codes as $code) {
            foreach ($dataset->ratesFor($code) as $rate) {
                $classification = Shape::map($rate['classification'] ?? null);
                $effective = Shape::map($rate['effective'] ?? null);
                $percentage = Shape::text($rate['percentage'] ?? null);

                $records[] = new RateRecord(
                    jurisdiction: Shape::text($rate['jurisdiction'] ?? null) ?? $code,
                    kind: Shape::text($rate['kind'] ?? null) ?? 'unknown',
                    percentage: $percentage === null || ! is_numeric($percentage) ? null : BigDecimal::of($percentage),
                    category: Shape::text($rate['category'] ?? null),
                    classificationScheme: Shape::text($classification['scheme'] ?? null),
                    classificationCode: Shape::text($classification['code'] ?? null),
                    from: $this->date($effective['from'] ?? null),
                    until: $this->date($effective['until'] ?? null),
                    atGeneralRate: ($rate['atGeneralRate'] ?? false) === true,
                );
            }
        }

        return $records;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        $text = Shape::text($value);

        if ($text === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($text);
        } catch (Throwable) {
            return null;
        }
    }
}
