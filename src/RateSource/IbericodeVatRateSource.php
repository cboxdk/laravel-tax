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
 * Reads EU VAT rates from the community-maintained, MIT-licensed
 * **ibericode/vat-rates** dataset — a real, public feed of EU member-state VAT
 * rates (a fork of adamcooke/vat-rates). Its canonical location is
 * `https://raw.githubusercontent.com/ibericode/vat-rates/master/vat-rates.json`,
 * but the source is CONFIG-DRIVEN (`tax.eu_vat.url`): pass an `http(s)://` URL or a
 * local filesystem path. The static snapshot stays the zero-config default; this
 * feed activates only when an operator enables it (see the service provider).
 *
 * The dataset is NOT the EU Commission TEDB itself — it is a community compilation.
 * Its documented shape (schema `version: 4`):
 *
 *     {
 *       "details": "https://github.com/ibericode/vat-rates",
 *       "version": 4,
 *       "items": {
 *         "DK": [ { "effective_from": "0000-01-01", "rates": { "standard": 25 } } ],
 *         "FR": [
 *           { "effective_from": "2014-01-01",
 *             "rates": { "super_reduced": 2.1, "reduced1": 5.5, "reduced2": 10, "standard": 20 },
 *             "exceptions": [ { "name": "Guadeloupe", "postcode": "971\\d{2,}", "standard": 8.5 } ] }
 *         ]
 *       }
 *     }
 *
 * Each country maps to a list of rate PERIODS, newest last, keyed by
 * `effective_from` (`YYYY-MM-DD`, with `0000-01-01` as the open-ended base). The
 * adapter selects the period in force at `$at` (or now) and resolves the standard
 * rate. The dataset carries reduced tiers (`reduced`, `reduced1`, `reduced2`,
 * `super_reduced`, `parking`, `press_publications`) but does NOT map them to
 * product categories — so this adapter resolves the STANDARD rate by default and
 * only returns a reduced tier when the operator supplies an authoritative
 * category -> tier map via `$categoryTiers` (e.g. `['digital_service' => 'reduced1']`).
 * The package never guesses which tier a category belongs to.
 *
 * Territorial `exceptions` (Canary Islands, French overseas départements, …) are
 * postcode-scoped and not applied at country granularity. A missing country, an
 * unreadable source or malformed JSON yields `null`, so the engine denies (and a
 * composed {@see ChainTaxRateSource} falls back to the static snapshot) rather than
 * guessing.
 *
 * A URL source fetches per lookup, so wrap it in {@see CachingTaxRateSource}.
 */
readonly class IbericodeVatRateSource implements TaxRateSource
{
    /**
     * @param  array<string, string>  $categoryTiers  TaxCategory value -> dataset tier key
     *                                                (e.g. ['digital_service' => 'reduced1']).
     *                                                Empty resolves the standard rate for every category.
     */
    public function __construct(
        private Factory $http,
        private string $location,
        private array $categoryTiers = [],
        private string $source = 'ibericode-vat-rates',
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

        $items = $dataset['items'] ?? null;

        if (! is_array($items)) {
            return null;
        }

        $periods = $items[$jurisdiction->country->value] ?? null;

        if (! is_array($periods)) {
            return null;
        }

        $rates = $this->ratesInForce($periods, $at);

        if ($rates === null) {
            return null;
        }

        // A category the operator has authoritatively mapped to a reduced tier
        // resolves that tier; otherwise the standard rate applies.
        $tierKey = $this->categoryTiers[$category->value] ?? null;

        if (is_string($tierKey) && $tierKey !== 'standard') {
            $reduced = $this->number($rates[$tierKey] ?? null);

            if ($reduced !== null) {
                return new TaxRate($reduced, $this->kindForTier($tierKey), $this->source, Confidence::Authoritative);
            }
        }

        $standard = $this->number($rates['standard'] ?? null);

        if ($standard === null) {
            return null;
        }

        return new TaxRate($standard, RateKind::Standard, $this->source, Confidence::Authoritative);
    }

    /**
     * Select the `rates` map of the period in force at the reference date — the
     * period with the greatest `effective_from` that is not after the reference
     * date. `effective_from` is `YYYY-MM-DD`, so lexical comparison is chronological.
     *
     * @param  array<mixed>  $periods
     * @return array<string, mixed>|null
     */
    private function ratesInForce(array $periods, ?DateTimeImmutable $at): ?array
    {
        $reference = ($at ?? new DateTimeImmutable)->format('Y-m-d');

        $chosen = null;
        $chosenFrom = null;

        foreach ($periods as $period) {
            if (! is_array($period)) {
                continue;
            }

            $from = $period['effective_from'] ?? null;
            $rates = $period['rates'] ?? null;

            if (! is_string($from) || ! is_array($rates) || $from > $reference) {
                continue;
            }

            if ($chosenFrom === null || $from > $chosenFrom) {
                $chosenFrom = $from;
                /** @var array<string, mixed> $rates */
                $chosen = $rates;
            }
        }

        return $chosen;
    }

    private function kindForTier(string $tierKey): RateKind
    {
        return $tierKey === 'zero' ? RateKind::Zero : RateKind::Reduced;
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
