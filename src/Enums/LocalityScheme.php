<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

use Cbox\Tax\Territories\UsLocalStructure;

/**
 * What a locality code MEANS, so a rate source knows how to read one.
 *
 * A `LocalityCode` carries a string and a scheme, and the scheme is the whole
 * difference between a key that must be expanded and an authority that can be used
 * as it stands. A ZIP+4 is not a taxing authority — it is a postal key that a
 * boundary index turns into the set of authorities covering that address; a county
 * name is a body that levies. Reading one as the other silently bills the wrong
 * jurisdiction.
 *
 * These lived as loose `const string` on the rate sources that consumed them, which
 * made an unrelated class — the geocoder — import a rate source to name a scheme it
 * emits. They are a vocabulary shared between whoever produces a locality and
 * whoever resolves one, so they belong to neither.
 */
enum LocalityScheme: string
{
    /**
     * A full ZIP+4 (`66101-3064`): a postal key the boundary index expands into the
     * taxing authorities that apply there, never an authority code itself.
     */
    case Zip9 = 'zip9';

    /**
     * A county NAME (`Alachua County`) for the states in
     * {@see UsLocalStructure::countyResolvedStates()}, where
     * the county is the only local authority that can apply.
     */
    case County = 'county';

    /**
     * A geographic point, for the states that publish their own polygons and so
     * resolve finer than any postal key can.
     */
    case LatLng = 'latlng';

    /**
     * A taxing authority's own code, as the source files it — the Streamlined FIPS
     * that a member state publishes its rates under.
     *
     * Not a place to be resolved but an authority already resolved, by whatever
     * knew better: a host's own boundary data, a certification test deck, a
     * seller's ship-from that is recorded as a jurisdiction rather than an address.
     */
    case Authority = 'sst-fips';
}
