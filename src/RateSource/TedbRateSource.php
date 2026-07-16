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
 * Reads EU VAT rates from a TEDB-derived dataset — the EU Commission's *Taxes in
 * Europe Database* (`VatRetrievalService`), transformed to the JSON shape below.
 * The dataset location is CONFIG-DRIVEN (`tax.tedb.url`): either an `http(s)://`
 * URL or a local filesystem path. The package ships NO endpoint — an operator must
 * point this at a real TEDB export; unconfigured, the static snapshot stays the
 * zero-config default.
 *
 * Documented dataset shape (a TEDB export normalised to per-country rates):
 *
 *     {
 *       "version": "2026-07-01",
 *       "rates": {
 *         "DK": { "standard": "25" },
 *         "FR": { "standard": "20", "bands": { "ebook": { "rate": "5.5", "kind": "reduced" } } }
 *       }
 *     }
 *
 * A country entry's `standard` is the standard rate; an optional `bands` map keys
 * reduced/zero rates by taxability category (matching {@see TaxCategory} values). A
 * category with a matching band resolves that band; otherwise the standard rate
 * applies. A missing country, an unreadable source, or malformed JSON yields
 * `null` so the engine denies (and a composed {@see ChainTaxRateSource} can fall
 * back to the static snapshot) rather than guessing.
 *
 * A URL source fetches per lookup, so wrap it in {@see CachingTaxRateSource}.
 */
readonly class TedbRateSource implements TaxRateSource
{
    public function __construct(
        private Factory $http,
        private string $location,
        private string $source = 'tedb',
    ) {}

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxCategory $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        $dataset = $this->load();

        if ($dataset === null) {
            return null;
        }

        $rates = $dataset['rates'] ?? null;

        if (! is_array($rates)) {
            return null;
        }

        $entry = $rates[$jurisdiction->country->value] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        $band = $this->band($entry, $category);

        if ($band !== null) {
            return $band;
        }

        $standard = $this->number($entry['standard'] ?? null);

        if ($standard === null) {
            return null;
        }

        return new TaxRate($standard, RateKind::Standard, $this->source, Confidence::Authoritative);
    }

    /**
     * Load and decode the dataset from the configured URL or file path. Any
     * transport/read/parse failure returns `null` (deny-by-default).
     *
     * @return array<mixed>|null
     */
    private function load(): ?array
    {
        $raw = str_starts_with($this->location, 'http://') || str_starts_with($this->location, 'https://')
            ? $this->fetch()
            : $this->readFile();

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function fetch(): ?string
    {
        try {
            $response = $this->http->acceptJson()->get($this->location);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    private function readFile(): ?string
    {
        if (! is_file($this->location) || ! is_readable($this->location)) {
            return null;
        }

        $raw = file_get_contents($this->location);

        return $raw === false ? null : $raw;
    }

    /**
     * Extract a reduced/zero band for the category from a country entry's `bands`
     * map, if present and well-formed.
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
