<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

use Cbox\Tax\Contracts\TaxRegister;

/**
 * Every kind of rule the register publishes, and what this engine does with it.
 *
 * A RULE KIND NOBODY READS IS A SILENT HOLE. Idaho's minimum taxable sale was
 * published for months and charged anyway, because nothing listed the kinds the
 * engine reads against the kinds the register publishes. This does: a kind is either
 * applied, or reviewed and deliberately not applied with the reason beside it, and a
 * kind in neither list is named when a release is compiled and fails the check against
 * the live register.
 *
 * @internal Changes with the register's format. Applications ask the register through
 *           {@see TaxRegister}.
 */
class RuleKinds
{
    /** Kinds the engine applies to an answer. */
    public const array APPLIED = [
        'declined_rate',
        'holiday',
        'marketplace_facilitator',
        'minimum_taxable_sale',
        'price_exemption',
        'remote_seller_election',
        'rounding',
        'sourcing',
        'taxable_base',
        'threshold',
    ];

    /** Kinds reviewed and deliberately not applied, and why. */
    public const array REVIEWED = [
        'attribution' => 'Who accounts for the tax on a domestic supply by a supplier not established there '
            .'(Art. 194). Every published rule says "the recipient" without saying which supplies it covers, '
            .'and the member states differ: Germany reverses work deliveries and services but not plain '
            .'deliveries of goods. Applied as published it would reverse-charge sales that are the '
            .'supplier\'s to charge.',
        'margin_scheme' => 'VAT on the margin for second-hand goods, art and antiques. It needs the purchase '
            .'price and the seller\'s election, which a supply does not carry; a host that uses the scheme '
            .'computes it before the engine.',
        'self_collecting' => 'Who collects a local tax, not what it is: a filing question, not a price.',
    ];

    /**
     * The kinds in a list that this engine neither applies nor has set aside.
     *
     * @param  iterable<mixed>  $kinds
     * @return list<string>
     */
    public static function unknown(iterable $kinds): array
    {
        $unknown = [];

        foreach ($kinds as $kind) {
            if (is_string($kind) && ! in_array($kind, self::APPLIED, true) && ! array_key_exists($kind, self::REVIEWED)) {
                $unknown[$kind] = true;
            }
        }

        $names = array_keys($unknown);
        sort($names);

        return $names;
    }
}
