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
 * Codes are the dataset's own local authority codes for the state (`US-CO:DENVER`
 * and so on) — see the `rates` section. A code the dataset does not carry makes the
 * whole stack refuse and fall back to the state rate rather than silently dropping
 * that authority's share.
 */
interface LocalAuthorityResolver
{
    /**
     * The local authority codes that apply at a jurisdiction, or null to defer.
     *
     * `$at` is the SUPPLY date, not today: an address changes hands between
     * districts, and pricing a backdated credit note needs the authorities that
     * applied then. Implementations that cannot answer historically should return
     * null for a past date rather than today's answer.
     *
     * @return list<string>|null
     */
    public function authoritiesFor(Jurisdiction $jurisdiction, ?DateTimeImmutable $at = null): ?array;
}
