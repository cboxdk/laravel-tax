<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use DateTimeImmutable;

/**
 * The four questions the US sales-tax regime asks of published data, and nothing
 * else.
 *
 * The regime used to take a concrete dataset class, which tied a piece of tax LOGIC
 * to one particular publisher: swapping the data source meant editing the regime,
 * and a host with its own compilation could not supply these at all. Each of these
 * is a fact somebody publishes, so each belongs behind a contract like every other
 * sourced fact in this package.
 *
 * Every method is nullable, and null means "nothing published says so" rather than
 * "no". The regime treats each absence in the direction that can be corrected
 * afterwards: no marketplace law in force means the seller collects, and an
 * election that cannot be priced refuses outright rather than guessing a rate.
 */
interface UsTaxFacts
{
    /**
     * The state's own rate on a date, as a decimal string (`'6.5'`).
     *
     * A string, never a float: parsing `19.99` into binary and back is how a rate
     * becomes `19.989999999999998`, and these figures are quoted from a statute.
     */
    public function stateRatePercent(string $state, ?DateTimeImmutable $at = null): ?string;

    /**
     * The sales-tax holiday covering a class of supply on a date, if one does.
     *
     * `cap` is in MINOR units and `capInclusive` settles whether an item priced
     * exactly at the ceiling is exempt — one word in a statute, one boundary case,
     * and read wrong in four states by the compilation this package used to read.
     *
     * @return array{name: string, cap: int, capInclusive: bool}|null
     */
    public function salesTaxHoliday(string $state, string $class, string $on): ?array;

    /**
     * The elected remote-seller programme in force in a state on a date.
     *
     * `mechanic` says what the rate REPLACES, and the difference is the whole bill:
     * Alabama's Simplified Sellers Use Tax is a `flat_total` standing in for the
     * state share and every local one, while Texas's single local rate replaces only
     * the local share with the state rate still charged on top. Both come to eight
     * per cent of the sale, which is a coincidence.
     *
     * @return array{program: string, mechanic: string, ratePercent: string, statute: string}|null
     */
    public function remoteSellerElection(string $state, string $on): ?array;

    /**
     * The date a state's marketplace-facilitator law took effect (`Y-m-d`), or null
     * where nothing published says one did.
     */
    public function marketplaceFacilitatorFrom(string $state): ?string;
}
