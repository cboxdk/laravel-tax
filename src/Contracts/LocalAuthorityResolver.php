<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\Jurisdiction;
use DateTimeImmutable;

/**
 * Resolves an address to the LOCAL TAXING AUTHORITIES that apply there, for states
 * the shipped dataset cannot resolve below the state line.
 *
 * WHY THIS IS A CONTRACT AND NOT AN IMPLEMENTATION. Several states run their own
 * free address-level lookup, and Colorado's carries something no commercial data
 * feed can offer: under CRS 39-26-105.2 a vendor who relies on the Department's GIS
 * database is HELD HARMLESS in an audit for local tax that was wrong because the
 * database was. That protection attaches to the vendor who used it — so it cannot
 * be obtained on a customer's behalf by proxying their lookups through somebody
 * else's credentials. Each seller registers, each seller holds their own key, and
 * each seller earns their own protection. A contract is therefore the only shape
 * this can take; shipping an implementation with our key would quietly strip the
 * one benefit that made the source worth using.
 *
 * The same seam serves any state where the host has better resolution than the
 * dataset does — a commercial adapter, an internal boundary file, a state portal.
 *
 * THREE ANSWERS, and the difference between the last two is where the money is:
 *
 *  - `null` — "I do not answer for this address." The engine falls through to its
 *    own resolution (a ZIP+4 boundary index, a county, a polygon service) exactly
 *    as if no resolver were bound. This is the right answer for every state your
 *    implementation does not cover, and the right answer when the lookup FAILED:
 *    an unreachable service is not knowledge that no tax applies.
 *  - `[]` — "no local authority taxes this address." A positive finding. The engine
 *    prices at the state share and calls it authoritative, because that is the
 *    whole rate, not a fallback to part of one.
 *  - a list of codes — the authorities that apply, keyed as the dataset carries
 *    them. EVERY one that applies must be listed. The engine sums them, and a
 *    short list is an under-charge stamped `Authoritative`, which is the one
 *    outcome this package works hardest to prevent.
 *
 * Codes are the REGISTER's jurisdiction codes — `us:CO:CITY-DENVER`, `us:KS:COUNTY-209`
 * — the same keys its rate records are filed under. Level and code together, because
 * a county and a special district can file under the same number and levy
 * separately. A code the register does not carry makes the whole stack refuse and
 * fall back to the state rate rather than silently dropping that authority's share.
 *
 * The state is one of them where the state's own rate applies, and it is the bare
 * state code: `us:KS`. That mirrors the boundary format, where the file itself says
 * whether the state share is due rather than leaving a consumer to add it.
 */
interface LocalAuthorityResolver
{
    /**
     * The local authority codes that apply at a jurisdiction, or null to defer.
     *
     * `$at` is the SUPPLY date, not today: an address changes hands between
     * districts, and pricing a backdated credit note needs the authorities that
     * applied then. An implementation with a dated source — a state portal, a feed
     * that versions its boundaries — is expected to use it, and to return null for a
     * date it cannot reach rather than today's answer dressed as that date's.
     *
     * An implementation reading an UNDATED SNAPSHOT is in a different position, and
     * pretending otherwise helps nobody: it has one set of boundaries and no way to
     * narrow them. Such an implementation answers from the snapshot it holds and must
     * SAY SO in its own docblock, naming what a caller does to price an older supply
     * correctly. What it must not do is accept `$at` and quietly ignore it, which
     * reads from the outside exactly like a resolver that honoured it.
     *
     * @return list<string>|null
     */
    public function authoritiesFor(Jurisdiction $jurisdiction, ?DateTimeImmutable $at = null): ?array;
}
