<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\ValueObjects\RateBand;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * A rate source backed by a static map of jurisdiction → standard-rate percentage,
 * with an optional per-(jurisdiction, category) map of reduced/zero bands. It ships
 * representative national standard rates so the engine works out of the box, but
 * these are DATA that changes — production deployments should bind a live source
 * (an EU TEDB adapter, the SST files, a commercial adapter) instead.
 *
 * Reduced/zero bands are intentionally EMPTY by default: the package will not
 * fabricate a national reduced-rate table. Supply your own bands (or bind a feed
 * that carries them) to resolve a reduced rate for a category.
 *
 * Lookups prefer a subdivision-level entry (US states, Canadian provinces), then
 * fall back to the national entry; a jurisdiction with no entry returns `null` so
 * the engine denies rather than assuming 0%.
 */
readonly class StaticTaxRateSource implements TaxRateSource
{
    /** The shipped overlay: dated rate windows per jurisdiction, with its authority. */
    private const string OVERLAY = __DIR__.'/../../resources/rates.json';

    /** @var array<string, list<array{from: ?string, to: ?string, rate: string}>> */
    private array $windows;

    /**
     * @param  array<string, string>|null  $rates  Country/subdivision code → standard percentage. Supplying
     *                                             one collapses every jurisdiction to a single open window;
     *                                             null loads the shipped dated overlay.
     * @param  array<string, RateBand>  $bands  "<jurisdiction>:<category>" → reduced/zero band, e.g. "DK:ebook".
     */
    public function __construct(?array $rates = null, private array $bands = [])
    {
        $this->windows = $rates === null
            ? self::overlay()
            : array_map(static fn (string $rate): array => [['from' => null, 'to' => null, 'rate' => $rate]], $rates);
    }

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxCategory $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        // A supply may legally carry a reduced/zero band for its category; prefer
        // that over the standard rate when the bound source supplies one.
        $band = $this->bandFor($jurisdiction, $category);

        if ($band !== null) {
            return new TaxRate(
                percentage: $band->percentage,
                kind: $band->kind,
                source: 'static',
                confidence: Confidence::Derived,
            );
        }

        if ($jurisdiction->country->value === 'US' && $category === TaxCategory::DigitalService) {
            return null;
        }

        // Prefer a subdivision-level rate (US states, Canadian provinces), then
        // fall back to the national rate — each resolved for the DATE asked about,
        // so reissuing an old invoice reprices at the rate that applied then.
        $on = ($at ?? new DateTimeImmutable)->format('Y-m-d');
        $percentage = null;

        if ($jurisdiction->subdivision !== null) {
            $percentage = $this->windowFor($jurisdiction->subdivision->value, $on);
        }

        if ($percentage === null) {
            $percentage = $this->windowFor($jurisdiction->country->value, $on);
        }

        if ($percentage === null) {
            return null;
        }

        return new TaxRate(
            percentage: $percentage,
            kind: RateKind::Standard,
            source: 'static',
            confidence: Confidence::Derived,
        );
    }

    /**
     * The rate in effect for a jurisdiction on a date. Null when the jurisdiction is
     * unknown, or known but with no window covering that date — a rate that had not
     * been introduced yet is a genuine absence, not a reason to use today's.
     */
    private function windowFor(string $code, string $on): ?string
    {
        foreach ($this->windows[$code] ?? [] as $window) {
            if (($window['from'] === null || $window['from'] <= $on)
                && ($window['to'] === null || $on <= $window['to'])) {
                return $window['rate'];
            }
        }

        return null;
    }

    /**
     * Resolve a reduced/zero band for the (jurisdiction, category), preferring a
     * subdivision-level entry over a national one. Returns `null` when no band is
     * configured — the caller then applies the standard rate.
     */
    private function bandFor(Jurisdiction $jurisdiction, TaxCategory $category): ?RateBand
    {
        $scopes = [];

        if ($jurisdiction->subdivision !== null) {
            $scopes[] = $jurisdiction->subdivision->value;
        }

        $scopes[] = $jurisdiction->country->value;

        foreach ($scopes as $scope) {
            $band = $this->bands[$scope.':'.$category->value] ?? null;

            if ($band !== null) {
                return $band;
            }
        }

        return null;
    }

    /**
     * The current standard rate per jurisdiction — the window in effect today,
     * flattened. Kept for callers that want the plain map; the source itself reads
     * the dated windows, so a historical query is answered from the right one.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        $today = new DateTimeImmutable()->format('Y-m-d');
        $rates = [];

        foreach (self::overlay() as $code => $windows) {
            foreach ($windows as $window) {
                if (($window['from'] === null || $window['from'] <= $today)
                    && ($window['to'] === null || $today <= $window['to'])) {
                    $rates[$code] = $window['rate'];

                    break;
                }
            }
        }

        return $rates;
    }

    /**
     * The shipped overlay, decoded. A missing or malformed file yields an empty map
     * rather than a guess — the engine then denies, as it does for any unknown
     * jurisdiction.
     *
     * @return array<string, list<array{from: ?string, to: ?string, rate: string}>>
     */
    private static function overlay(): array
    {
        /** @var array<string, list<array{from: ?string, to: ?string, rate: string}>>|null $cached */
        static $cached = null;

        if (is_array($cached)) {
            return $cached;
        }

        $raw = is_readable(self::OVERLAY) ? file_get_contents(self::OVERLAY) : false;
        $decoded = $raw === false ? null : json_decode($raw, true);
        $rates = is_array($decoded) && is_array($decoded['rates'] ?? null) ? $decoded['rates'] : [];

        /** @var array<string, list<array{from: ?string, to: ?string, rate: string}>> $windows */
        $windows = [];

        foreach ($rates as $code => $entry) {
            if (! is_string($code) || ! is_array($entry) || ! is_array($entry['windows'] ?? null)) {
                continue;
            }

            foreach ($entry['windows'] as $window) {
                if (! is_array($window) || ! is_string($window['rate'] ?? null)) {
                    continue;
                }

                $windows[$code][] = [
                    'from' => is_string($window['from'] ?? null) ? $window['from'] : null,
                    'to' => is_string($window['to'] ?? null) ? $window['to'] : null,
                    'rate' => $window['rate'],
                ];
            }
        }

        return $cached = $windows;
    }
}
