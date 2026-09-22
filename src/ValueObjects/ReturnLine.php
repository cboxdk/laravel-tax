<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\TaxTreatment;

/**
 * One line of an aggregated tax return: the net and tax totals (and the number of
 * taxable supplies) for a single taxing jurisdiction in a single currency. The
 * jurisdiction is a country plus, where the tax is sub-federal (a US state, a
 * Canadian province), its `subdivision` — so a return can drive a per-state /
 * per-member-state filing rather than collapsing everything to the country.
 */
readonly class ReturnLine
{
    public function __construct(
        public CountryCode $country,
        public ?SubdivisionCode $subdivision,
        public string $currency,
        public Money $net,
        public Money $tax,
        public int $count,
        /**
         * What each taxing authority is owed across the period — the figures a
         * remittance is actually made from. A Missouri return is not one number:
         * the state, the county and the city are each paid separately.
         *
         * NULL means the split is unknown for this jurisdiction, and the only
         * honest thing to do with it is treat it as missing data. It is null when
         * any taxed supply in the period arrived without a breakdown, or with an
         * authority share carrying neither code nor name — you cannot remit to an
         * authority you cannot identify, and a roll-up that quietly omitted it
         * would still add up to a plausible-looking figure.
         *
         * @var list<AuthorityTotal>|null
         */
        public ?array $authorities = null,
        /**
         * What this line is made of, per treatment, keyed by the treatment's value.
         *
         * The line's own `net` and `tax` are the whole period for that place; a form
         * asks for it split — an exempt intra-Community supply of goods apart from a
         * service the customer reverse-charges, both apart from what the seller
         * actually charged, and all three apart from what a marketplace remitted.
         * Every one of those but the first is a zero, so a single total cannot be
         * taken apart again afterwards. Added last, so positional callers are
         * unaffected.
         *
         * @var array<string, ReturnTotal>
         */
        public array $byTreatment = [],
    ) {}

    /** One treatment's share of this line, or null where the period held none. */
    public function forTreatment(TaxTreatment $treatment): ?ReturnTotal
    {
        return $this->byTreatment[$treatment->value] ?? null;
    }
}
