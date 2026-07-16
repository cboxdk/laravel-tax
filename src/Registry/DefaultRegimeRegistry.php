<?php

declare(strict_types=1);

namespace Cbox\Tax\Registry;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\TaxRegime;
use Cbox\Tax\Regime\CaGstRegime;
use Cbox\Tax\Regime\EuVatRegime;
use Cbox\Tax\Regime\IndiaGstRegime;
use Cbox\Tax\Regime\MalaysiaSstRegime;
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
     *
     * The optional {@see JurisdictionRepository} lets the EU regime resolve the
     * seller's origin jurisdiction for Art. 59c micro-business sourcing; without it
     * the regime falls back to destination taxation.
     */
    public static function withDefaults(
        ?ProductTaxability $taxability = null,
        ?JurisdictionRepository $jurisdictions = null,
    ): self {
        $national = new NationalTaxRegime;

        return new self([
            'eu-vat' => new EuVatRegime($jurisdictions),
            'uk-vat' => $national,
            'ch-vat' => $national,
            'no-vat' => $national,
            'au-gst' => $national,
            'nz-gst' => $national,
            'mx-iva' => $national,
            'sg-gst' => $national,
            'tw-vat' => $national,
            'ae-vat' => $national,
            'sa-vat' => $national,
            'bh-vat' => $national,
            'om-vat' => $national,
            'tr-vat' => $national,
            'cl-iva' => $national,
            'id-ppn' => $national,
            'vn-vat' => $national,
            'ph-vat' => $national,
            'jp-ct' => $national,
            'kr-vat' => $national,
            'th-vat' => $national,
            'ua-vat' => $national,
            'my-sst' => new MalaysiaSstRegime,
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
