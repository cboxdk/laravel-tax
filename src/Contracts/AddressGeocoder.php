<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\Jurisdiction;

/**
 * Resolves a free-form address to a precise {@see Jurisdiction} — the seam for
 * rooftop/address-level geocoding where a country + subdivision is not enough to
 * determine the taxing jurisdiction (US sub-federal rate stacking).
 *
 * Optional and deny-by-default: an implementation returns `null` when it cannot
 * resolve the address to a jurisdiction confidently, and the caller must refuse
 * rather than guess from a ZIP centroid. A default binding (e.g. Geocodio) is
 * recommended for US/CA but not required for national VAT/GST jurisdictions.
 */
interface AddressGeocoder
{
    /**
     * @param  array<string, string|null>  $address  Structured address parts
     *                                               (line1, city, postalCode,
     *                                               country, subdivision).
     */
    public function locate(array $address): ?Jurisdiction;
}
