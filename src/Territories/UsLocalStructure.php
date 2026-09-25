<?php

declare(strict_types=1);

namespace Cbox\Tax\Territories;

use Cbox\Tax\Contracts\UsTaxFacts;

/**
 * Claims about how the United States is structured for local sales tax — which
 * states put a taxing authority below the county line, which do not, and which
 * publish geometry fine enough to resolve a point against.
 *
 * These are statements about law, not observations of any dataset, which is why
 * they are written lists with their grounds rather than something derived from
 * records. They used to sit as static methods on the rate source that read the
 * us-tax-data dataset, which meant the geocoder imported a rate source to ask a
 * question about Virginia. They outlive any particular data source, so they live
 * apart from all of them.
 */
class UsLocalStructure
{
    /**
     * The states where the COUNTY is the only local authority that can apply, so
     * resolving the county resolves the rate exactly — no boundary file needed.
     *
     * This is a legal claim about each state's taxing structure, not an observation
     * of today's data, which is why it is a written list with its grounds rather
     * than something derived from the records:
     *
     *  - **FL** — ch. 212.055 authorizes the discretionary sales surtax to COUNTIES.
     *    The Department of Revenue's own surtax table is published per county, all 67.
     *  - **PA** — only two local taxes exist: Allegheny County at 1% and Philadelphia
     *    at 2%. Philadelphia is carried as a city because that is what it is called,
     *    but the city and the county are coterminous, so a county resolves it.
     *  - **HI** — the counties may adopt a GET surcharge by ordinance; four have.
     *  - **VA** — a Virginia city is by law INDEPENDENT of any county, so a city
     *    there is a county-equivalent (FIPS class C7) rather than something sitting
     *    inside one. Nothing can be below it. The state's own rate page bands all 39
     *    localities by county or independent city, and the dataset carries 39.
     *
     * VIRGINIA ALSO SHOWS WHY THE NAME MATCH IS ORDERED. `Fairfax County` and
     * `Fairfax City` are different authorities over different ground, and so are
     * Franklin, Richmond and Roanoke. A match that dropped the unit word would make
     * each pair ambiguous and refuse — costing Fairfax its regional rate for no
     * reason. {@see UsTaxFacts::localCodeForCounty()} tries the full name first.
     *
     * SOUTH CAROLINA IS DELIBERATELY ABSENT and the reason is the point of this
     * list. Its local option taxes look county-level, and 46 of the dataset's 47 SC
     * authorities are counties — but Myrtle Beach levies its own 1% Tourism
     * Development tax ON TOP of Horry County's. Resolving only the county there
     * would UNDER-charge, which is the direction that cannot be refunded later. A
     * state joins this list when nothing can sit below the county line, not when
     * almost nothing does.
     *
     * Two guards hold the list to that claim, and they sit at different layers on
     * purpose. `tests/Feature/CountyResolvedRateTest.php` checks the engine's
     * behaviour here; `bin/check-county-resolved.php` in the us-tax-data repo checks
     * the PUBLISHED data every drift run, which is where a newly-adopted city tax
     * would actually show up. Adding a state means changing both.
     *
     * @return list<string>
     */
    public static function countyResolvedStates(): array
    {
        return ['US-FL', 'US-PA', 'US-HI', 'US-VA'];
    }

    /**
     * Authority codes that are NOT counties but are coterminous with one, so a
     * county resolution reaches them correctly.
     *
     * Philadelphia is the only NAMED one, and it is named because it is a one-off:
     * a single consolidated city-county in a state whose other authority is an
     * ordinary county. It exists so the guard can tell "a city that IS the county"
     * apart from "a city inside a county" — the distinction that keeps South
     * Carolina out over Myrtle Beach.
     *
     * Virginia is handled by rule instead, in {@see self::countyEquivalentCityStates()},
     * because there it is not an exception but the entire structure.
     *
     * @return list<string>
     */
    public static function coterminousCityCounties(): array
    {
        return ['US-PA:Philadelphia'];
    }

    /**
     * States where EVERY city is a county-equivalent, so a city-level record needs
     * no individual exemption.
     *
     * Virginia only. Under Virginia law every municipality incorporated as a city is
     * independent of any county — there is no such thing as a Virginia city inside a
     * county, which is why the Census treats all 38 as county-equivalents. Listing
     * the 17 that levy a regional rate would read as 17 exceptions to a rule; there
     * is no rule for them to be exceptions to.
     *
     * Note this covers CITIES, not TOWNS. A Virginia town IS inside a county, so a
     * town-level record would be a genuine sub-county authority and the guard fails
     * on it — correctly.
     *
     * @return list<string>
     */
    public static function countyEquivalentCityStates(): array
    {
        return ['US-VA'];
    }

    /**
     * States resolved by point against published polygons.
     *
     * FALLBACK ONLY. The geocoder reads this from the installed register — a state
     * has point resolution exactly when its release carries a geometry artifact —
     * and consults this list only when it was built without a store.
     *
     * @return list<string>
     */
    public static function polygonResolvedStates(): array
    {
        return ['US-CA', 'US-NM'];
    }
}
