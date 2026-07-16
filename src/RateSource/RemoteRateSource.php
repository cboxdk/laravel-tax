<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Fetches rates from a remote JSON feed keyed by ISO country code — the pattern for
 * a live source such as an EU TEDB-derived dataset. Each country maps to either a
 * number (`{"DK": 25}`), an object with a `standard` key (`{"DK": {"standard": 25}}`),
 * or an object that additionally carries reduced/zero bands per category:
 *
 *     {"DK": {"standard": 25, "bands": {"ebook": {"rate": 0, "kind": "zero"}}}}
 *
 * A lookup for a category with a matching band resolves that band; otherwise the
 * standard rate applies.
 *
 * It issues a request per lookup, so wrap it in {@see CachingTaxRateSource}. A
 * transport failure returns `null` (the engine then denies rather than guessing).
 */
readonly class RemoteRateSource implements TaxRateSource
{
    public function __construct(
        private Factory $http,
        private string $url,
        private string $source = 'remote',
    ) {}

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxCategory $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        try {
            $response = $this->http->acceptJson()->get($this->url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        if (! is_array($data)) {
            return null;
        }

        return $this->resolve($data[$jurisdiction->country->value] ?? null, $category);
    }

    /**
     * Resolve a country entry (number or object) to a rate for the category,
     * preferring a category band over the standard rate.
     */
    private function resolve(mixed $entry, TaxCategory $category): ?TaxRate
    {
        if (is_array($entry)) {
            $band = $this->band($entry, $category);

            if ($band !== null) {
                return $band;
            }
        }

        $standard = $this->number(is_array($entry) ? ($entry['standard'] ?? null) : $entry);

        if ($standard === null) {
            return null;
        }

        return new TaxRate($standard, RateKind::Standard, $this->source, Confidence::Authoritative);
    }

    /**
     * Extract a reduced/zero band for the category from a country entry's `bands`
     * map, if one is present and well-formed.
     *
     * @param  array<mixed>  $entry
     */
    private function band(array $entry, TaxCategory $category): ?TaxRate
    {
        $bands = $entry['bands'] ?? null;

        if (! is_array($bands)) {
            return null;
        }

        $band = $bands[$category->value] ?? null;

        if (! is_array($band)) {
            return null;
        }

        $percentage = $this->number($band['rate'] ?? null);

        if ($percentage === null) {
            return null;
        }

        $kind = $band['kind'] ?? null;
        $kind = is_string($kind) ? RateKind::tryFrom($kind) : null;

        return new TaxRate($percentage, $kind ?? RateKind::Reduced, $this->source, Confidence::Authoritative);
    }

    private function number(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        return null;
    }
}
