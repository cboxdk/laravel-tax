<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Tax\ValueObjects\ExchangeRate;
use DateTimeImmutable;

/**
 * The rate to state an invoice's tax in the currency of the place that levies it.
 *
 * An EU invoice in another currency must still state its VAT in the member state's
 * own: converted at the rate in force when the tax became chargeable, which Art. 91 of
 * the VAT Directive lets a member state set as the European Central Bank's. The
 * shipped implementation reads the ECB's reference rates from local disk; a host with
 * its own source — a national bank's, a monthly customs rate — binds its own.
 *
 * Never a network call while pricing. Null where no rate is known for the date, which
 * leaves the conversion to the host rather than guessing one.
 */
interface ExchangeRates
{
    /**
     * The rate from one ISO 4217 currency to another in force on the date: the latest
     * published on or before it.
     */
    public function rate(string $from, string $to, DateTimeImmutable $on): ?ExchangeRate;
}
