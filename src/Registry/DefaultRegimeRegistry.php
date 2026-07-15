<?php

declare(strict_types=1);

namespace Cbox\Tax\Registry;

use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\TaxRegime;
use Cbox\Tax\Regime\CaGstRegime;
use Cbox\Tax\Regime\EuVatRegime;
use Cbox\Tax\Regime\IndiaGstRegime;
use Cbox\Tax\Regime\NationalTaxRegime;
use Cbox\Tax\Regime\UsSalesTaxRegime;
use Cbox\Tax\Taxability\StaticProductTaxability;

/**
 * Maps regime-module keys to regime instances. Keys with no entry return `null`,
 * so the engine denies by default — a jurisdiction whose regime is not yet
 * implemented (e.g. the US/CA sub-federal regimes) is refused, never guessed.
 */
readonly class DefaultRegimeRegistry implements RegimeRegistry
{
    /**
     * @param  array<string, TaxRegime>  $regimes  regimeModule key => regime
     */
    public function __construct(private array $regimes) {}

    /**
     * The regimes shipped with the package: EU VAT, the single-rate national
     * VAT/GST regimes (UK, CH, NO, AU, NZ, MX), and the sub-federal regimes
     * (US sales tax, Canadian GST/HST).
     */
    public static function withDefaults(?ProductTaxability $taxability = null): self
    {
        $national = new NationalTaxRegime;

        return new self([
            'eu-vat' => new EuVatRegime,
            'uk-vat' => $national,
            'ch-vat' => $national,
            'no-vat' => $national,
            'au-gst' => $national,
            'nz-gst' => $national,
            'mx-iva' => $national,
            'sg-gst' => $national,
            'in-gst' => new IndiaGstRegime,
            'us-sales-tax' => new UsSalesTaxRegime($taxability ?? new StaticProductTaxability),
            'ca-gst' => new CaGstRegime,
        ]);
    }

    public function for(string $regimeModule): ?TaxRegime
    {
        return $this->regimes[$regimeModule] ?? null;
    }
}
