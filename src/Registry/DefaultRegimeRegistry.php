<?php

declare(strict_types=1);

namespace Cbox\Tax\Registry;

use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\TaxRegime;
use Cbox\Tax\Regime\EuVatRegime;
use Cbox\Tax\Regime\NationalTaxRegime;

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
     * The regimes shipped with the package: EU VAT, plus the single-rate national
     * VAT/GST regimes (UK, CH, NO, AU, NZ, MX). The sub-federal regimes
     * (us-sales-tax, ca-gst) are intentionally absent until implemented.
     */
    public static function withDefaults(): self
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
        ]);
    }

    public function for(string $regimeModule): ?TaxRegime
    {
        return $this->regimes[$regimeModule] ?? null;
    }
}
