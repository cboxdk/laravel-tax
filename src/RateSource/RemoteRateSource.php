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
 * Fetches standard rates from a remote JSON feed keyed by ISO country code — the
 * pattern for a live source such as an EU TEDB-derived dataset. The feed maps a
 * country to either a number (`{"DK": 25}`) or an object with a `standard` key
 * (`{"DK": {"standard": 25}}`).
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

        $percentage = $this->extract($data[$jurisdiction->country->value] ?? null);

        if ($percentage === null) {
            return null;
        }

        return new TaxRate($percentage, RateKind::Standard, $this->source, Confidence::Authoritative);
    }

    private function extract(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['standard'] ?? null;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        return null;
    }
}
