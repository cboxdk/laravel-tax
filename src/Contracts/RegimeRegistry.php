<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

/**
 * Maps a jurisdiction's `regimeModule` key (from its geo tax profile, e.g.
 * "eu-vat", "uk-vat") to the {@see TaxRegime} that implements it. Returns `null`
 * for a key with no registered regime, so the engine denies by default.
 */
interface RegimeRegistry
{
    public function for(string $regimeModule): ?TaxRegime;
}
