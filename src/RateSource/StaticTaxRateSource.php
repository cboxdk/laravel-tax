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

/**
 * A rate source backed by a static map of jurisdiction → standard-rate percentage.
 * It ships representative national standard rates so the engine works out of the
 * box, but these are DATA that changes — production deployments should bind a live
 * source (an EU TEDB adapter, the SST files, a commercial adapter) instead.
 *
 * Lookups are country-level; a jurisdiction with no entry returns `null` so the
 * engine denies rather than assuming 0%.
 */
readonly class StaticTaxRateSource implements TaxRateSource
{
    /** @var array<string, string> */
    private array $rates;

    /**
     * @param  array<string, string>|null  $rates  Country code → percentage; null uses the built-in defaults.
     */
    public function __construct(?array $rates = null)
    {
        $this->rates = $rates ?? self::defaults();
    }

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxCategory $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        // Prefer a subdivision-level rate (US states, Canadian provinces), then
        // fall back to the national rate.
        $percentage = null;

        if ($jurisdiction->subdivision !== null) {
            $percentage = $this->rates[$jurisdiction->subdivision->value] ?? null;
        }

        if ($percentage === null) {
            $percentage = $this->rates[$jurisdiction->country->value] ?? null;
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
     * Representative national standard VAT/GST rates. Illustrative defaults, not a
     * live feed — refresh or override in production.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            // EU member states.
            'AT' => '20', 'BE' => '21', 'BG' => '20', 'HR' => '25', 'CY' => '19',
            'CZ' => '21', 'DK' => '25', 'EE' => '22', 'FI' => '25.5', 'FR' => '20',
            'DE' => '19', 'GR' => '24', 'HU' => '27', 'IE' => '23', 'IT' => '22',
            'LV' => '21', 'LT' => '21', 'LU' => '17', 'MT' => '18', 'NL' => '21',
            'PL' => '23', 'PT' => '23', 'RO' => '19', 'SK' => '23', 'SI' => '22',
            'ES' => '21', 'SE' => '25',
            // Non-EU national regimes.
            'GB' => '20', 'CH' => '8.1', 'NO' => '25', 'AU' => '10', 'NZ' => '15',
            'MX' => '16',
            // US state base rates (local district rates stack on top via rooftop
            // resolution — these are illustrative state-level defaults).
            'US-CA' => '7.25', 'US-NY' => '4', 'US-TX' => '6.25', 'US-WA' => '6.5',
            'US-CO' => '2.9', 'US-FL' => '6', 'US-IL' => '6.25', 'US-OH' => '5.75',
            // Canadian provinces — combined GST/HST or GST+PST/QST (no local tax).
            'CA-ON' => '13', 'CA-QC' => '14.975', 'CA-BC' => '12', 'CA-AB' => '5',
            'CA-NS' => '14', 'CA-NB' => '15', 'CA-MB' => '12', 'CA-SK' => '11',
        ];
    }
}
