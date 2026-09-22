<?php

declare(strict_types=1);

namespace Cbox\Tax\Tests\Fixtures;

use Cbox\Tax\Testing\FakeRegister;

/**
 * The register this suite runs against: invented, fixed, and written down here so
 * every test can see what it is asserting against.
 *
 * NONE OF THESE FIGURES IS DATA. They are chosen to exercise the engine — a state
 * with locals and one without, a holiday inside its window and one outside, an
 * excess-taxable cap beside a cliff, an election that replaces the whole rate beside
 * one that replaces only the local share. Where a number happens to match a real
 * one it is because the real shape is what makes the test meaningful, not because
 * anything here is maintained.
 *
 * Real figures are asserted in the `e2e` group, against the live register.
 */
final class SuiteRegister
{
    public static function install(string $root): void
    {
        $register = FakeRegister::at($root);

        foreach (self::countries() as $code => $percentage) {
            // Dated from far enough back that a backdated supply still finds a rate;
            // the window matters to provenance, not to most of these cases.
            $register->rate('eu:'.$code, $percentage, from: '1990-01-01');
        }

        foreach (self::states() as $state => $percentage) {
            $register->rate('us:'.$state, $percentage, 'standard');
        }

        self::windows($register);
        self::bands($register);
        self::locals($register);
        self::rules($register);

        // Kansas City: the state, a county and a city all levy at 66101. Atchison's
        // 66002 carries a narrow span that a whole-ZIP row also covers, so first
        // match decides which county is paid.
        $register->boundary('KS', '66101', ['state:20', 'county:209', 'city:36000']);
        $register->boundary('KS', '66002', ['state:20', 'county:005']);
        $register->boundary('KS', '66002', ['state:20', 'county:087'], from: '5000', to: '5099');
        // A ZIP where nothing local applies — an ANSWER, not a gap.
        $register->boundary('KS', '67002', ['state:20']);

        $register->install();
    }

    /** @return array<string, string> */
    public static function countries(): array
    {
        return [
            'DK' => '25', 'FR' => '20', 'DE' => '19', 'GB' => '20', 'PT' => '23',             'PL' => '23', 'ES' => '21', 'IE' => '23', 'GR' => '24', 'SE' => '25', 'NL' => '21',
            'IT' => '22', 'AT' => '20', 'BE' => '21', 'FI' => '25.5', 'LU' => '17', 'CZ' => '21',
            'RO' => '19', 'NO' => '25', 'CH' => '8.1', 'JP' => '10', 'SG' => '9', 'IN' => '18',
            'MY' => '8', 'AU' => '10', 'NZ' => '15', 'AE' => '5', 'MX' => '16',
            'KR' => '10', 'TH' => '7', 'ID' => '11', 'PH' => '12', 'VN' => '10',
            'CL' => '19', 'OM' => '5', 'TW' => '5', 'UA' => '20',
        ];
    }

    /**
     * State shares. DE, MT, NH and OR levy no sales tax at all, which is a fact
     * worth having in the fixture rather than a gap — "no tax here" and "we hold
     * nothing about here" must not test the same.
     *
     * @return array<string, string>
     */
    public static function states(): array
    {
        return [
            'AL' => '4', 'AZ' => '5.6', 'CA' => '7.25', 'CO' => '2.9', 'CT' => '6.35',
            'FL' => '6', 'IL' => '6.25', 'KS' => '6.5', 'MA' => '6.25', 'MO' => '4.225',
            'NC' => '4.75', 'NJ' => '6.625', 'NY' => '4', 'OH' => '5.75', 'RI' => '7',
            'TN' => '7', 'TX' => '6.25', 'WA' => '6.5',
            'VA' => '5.3', 'PA' => '6', 'HI' => '4',
            'DE' => '0', 'MT' => '0', 'NH' => '0', 'OR' => '0',
        ];
    }

    /**
     * Reduced bands, including the classification-scoped ones.
     *
     * Hungary carries the shape that matters: a category with TWO live answers, each
     * scoped to a different customs code. On the category alone the band is refused
     * and the standard rate applies; with a code it resolves exactly. That is the
     * whole resolution rule in one country.
     */
    /**
     * Rates that MOVED, so a supply either side of the change prices differently.
     * A window that never closes cannot show that a date was honoured.
     */
    private static function windows(FakeRegister $register): void
    {
        $register->rate('apac:TR', '18', from: '1990-01-01', until: '2023-07-09');
        $register->rate('apac:TR', '20', from: '2023-07-10');
        $register->rate('gcc:SA', '5', from: '1990-01-01', until: '2020-06-30');
        $register->rate('gcc:SA', '15', from: '2020-07-01');
        $register->rate('gcc:BH', '5', from: '1990-01-01', until: '2021-12-31');
        $register->rate('gcc:BH', '10', from: '2022-01-01');
    }

    private static function bands(FakeRegister $register): void
    {
        // Hungary's standard rate carries a window a correction could name, which is
        // what provenance records rather than the release version alone.
        $register->rate('eu:HU', '27', 'standard', from: '2024-01-01');
        $register->rate('eu:HU', '5', 'reduced', 'goods.food', classification: '01022110');
        $register->rate('eu:HU', '18', 'reduced', 'goods.food', classification: '1806');
        $register->rate('eu:HU', '18', 'reduced', 'services.accommodation');

        $register->rate('eu:FR', '5.5', 'reduced', 'goods.publications.book');
        $register->rate('eu:FR', '5.5', 'reduced', 'goods.food');
        $register->rate('eu:FR', '10', 'reduced', 'services.accommodation');
        $register->rate('eu:DE', '7', 'reduced', 'goods.food');
        $register->rate('eu:DK', '0', 'zero', 'services.passenger_transport');
        $register->rate('eu:IE', '0', 'zero', 'goods.publications.book');
        $register->rate('eu:SE', '6', 'reduced', 'goods.publications.newspaper');
        $register->rate('eu:PT', '6', 'reduced', 'goods.food');
        $register->rate('eu:ES', '10', 'reduced', 'services.accommodation');
        $register->rate('eu:PL', '5', 'reduced', 'goods.food');

        // A US exemption, so a facilitated supply that is exempt reports exempt
        // rather than facilitated — a marketplace collects nothing on either, and
        // calling it facilitated asserts a tax that was never due.
        $register->rate('us:WA', '0', 'exempt', 'goods.medicine.prescription');
        // California does not tax SaaS. The gate order is the point: an elected
        // scheme must never turn an exempt category into a charge.
        $register->rate('us:CA', '0', 'exempt', 'services.digital');

        // Missouri reduces the STATE share on groceries and its localities still
        // levy on food — which is why a reduced category is not an all-in rate.
        $register->rate('us:MO', '1.225', 'reduced', 'goods.food.basic');
        $register->rate('eu:GR', '13', 'reduced', 'services.accommodation');
    }

    private static function locals(FakeRegister $register): void
    {
        // Kansas City: a county AND a city both levy, which is the case that proves
        // a resolver stacks every authority rather than the first one it finds.
        $register->rate('us:KS:COUNTY-209', '1', 'local_component');
        $register->rate('us:KS:CITY-36000', '1.625', 'local_component');
        $register->rate('us:KS:COUNTY-087', '1', 'local_component');
        $register->rate('us:KS:COUNTY-005', '1', 'local_component');

        // Texas, for the sourcing cases: an origin state where the seller's
        // authority and the buyer's are different places.
        // Texas is ORIGIN-sourced, so the seller's authority decides and the
        // buyer's is the fallback. The two carry different rates on purpose: if
        // sourcing were ignored the totals would be indistinguishable.
        $register->rate('us:TX:CITY-4109000', '0.5', 'local_component')->named('us:TX:CITY-4109000', 'Austin');
        $register->rate('us:TX:CITY-2109064', '1.5', 'local_component')->named('us:TX:CITY-2109064', 'Dallas');

        // Canada: the federal GST plus a province that files one harmonised total.
        $register->rate('ca:CA', '5', from: '1990-01-01');
        $register->rate('ca:ON', '13', 'combined', from: '1990-01-01')->named('ca:ON', 'Ontario');

        // California files ALL-IN totals, so a combined record must never be added
        // to the state share on top.
        $register->rate('us:CA:CITY-LOS-ANGELES', '9.5', 'combined');
        // New York City levies its own 4.5%, so New York State has locals to miss —
        // which is what makes a bare state answer there a floor rather than the whole
        // rate.
        $register->rate('us:NY:CITY-NEW-YORK', '4.5', 'local_component')->named('us:NY:CITY-NEW-YORK', 'New York City');
        $register->rate('us:CA:CITY-ALAMEDA', '10.75', 'combined')->named('us:CA:CITY-ALAMEDA', 'Alameda');

        // The four states where the county is the only local authority that can
        // apply and no boundary artifact exists, so a NAME is the only handle.
        // Virginia carries the pair that makes the match order matter: a Virginia
        // city is independent of any county, so `Fairfax County` and `Fairfax City`
        // are different authorities over different ground.
        $register->rate('us:FL:COUNTY-ALACHUA', '1.5', 'local_component')->named('us:FL:COUNTY-ALACHUA', 'Alachua County');
        $register->rate('us:FL:COUNTY-MARTIN', '0.5', 'local_component')->named('us:FL:COUNTY-MARTIN', 'Martin County');
        // Citrus levies nothing. That is a RESOLVED answer equal to the state share,
        // not a failure to resolve, and the confidence has to tell them apart.
        $register->rate('us:FL:COUNTY-CITRUS', '0', 'local_component')->named('us:FL:COUNTY-CITRUS', 'Citrus County');
        $register->rate('us:HI:HONOLULU', '0.5', 'local_component')->named('us:HI:HONOLULU', 'Honolulu County');
        $register->rate('us:PA:ALLEGHENY', '1', 'local_component')->named('us:PA:ALLEGHENY', 'Allegheny County');
        $register->rate('us:PA:PHILADELPHIA', '2', 'local_component')->named('us:PA:PHILADELPHIA', 'Philadelphia');
        // Virginia's Historic Triangle adds 1.7; the northern group adds 0.7. The
        // Fairfax pair is the reason the name match is ordered.
        $register->rate('us:VA:JAMES-CITY-COUNTY', '1.7', 'local_component')->named('us:VA:JAMES-CITY-COUNTY', 'James City County');
        $register->rate('us:VA:WILLIAMSBURG', '1.7', 'local_component')->named('us:VA:WILLIAMSBURG', 'Williamsburg');
        $register->rate('us:VA:FAIRFAX-COUNTY', '0.7', 'local_component')->named('us:VA:FAIRFAX-COUNTY', 'Fairfax County');
        $register->rate('us:VA:FAIRFAX', '0.7', 'local_component')->named('us:VA:FAIRFAX', 'Fairfax');
        $register->rate('us:FL:COUNTY-MIAMI-DADE', '1', 'local_component')->named('us:FL:COUNTY-MIAMI-DADE', 'Miami-Dade County');
        $register->rate('us:FL:COUNTY-ST-JOHNS', '1', 'local_component')->named('us:FL:COUNTY-ST-JOHNS', 'St. Johns County');
    }

    private static function rules(FakeRegister $register): void
    {
        // Economic nexus. The four states with no sales tax carry none at all.
        foreach (['AL' => '250000', 'CA' => '500000', 'KS' => '100000',
            'OH' => '100000', 'TX' => '500000',
            'WA' => '100000', 'MO' => '100000', 'AZ' => '100000'] as $state => $amount) {
            $register->rule('us:'.$state, 'threshold', [
                'amount' => $amount.'.00',
                'currency' => 'USD',
                'binds' => 'remote_seller',
                'measuredOver' => 'previous_or_current_calendar_year',
            ]);
        }

        // Connecticut and New Jersey keep a transaction limb, and they combine it
        // differently — which is the whole reason the combinator is a field.
        $register->rule('us:CT', 'threshold', [
            'amount' => '100000.00', 'currency' => 'USD', 'binds' => 'remote_seller',
            'transactions' => 200, 'combinator' => 'sales_and_transactions',
        ]);
        $register->rule('us:NJ', 'threshold', [
            'amount' => '100000.00', 'currency' => 'USD', 'binds' => 'remote_seller',
            'transactions' => 200, 'combinator' => 'sales_or_transactions',
        ]);
        $register->rule('us:NY', 'threshold', [
            'amount' => '500000.00', 'currency' => 'USD', 'binds' => 'remote_seller',
            'transactions' => 100, 'combinator' => 'sales_and_transactions',
        ]);

        // Arizona is absent on purpose: its published commencement is not trusted, and
        // a state with no date leaves the tax with the seller.
        foreach (['MO' => '2023-01-01', 'WA' => '2018-01-01', 'CA' => '2019-10-01', 'TX' => '2019-10-01'] as $state => $from) {
            $register->rule('us:'.$state, 'marketplace_facilitator', ['platformOwes' => true], from: $from);
        }

        // Two elections with genuinely different mechanics, both landing on 8% of a
        // $100 sale — which is a coincidence, and the reason `mechanic` is a field.
        $register->rule('us:AL', 'remote_seller_election', [
            'program' => 'Simplified Sellers Use Tax', 'mechanic' => 'flat_total',
            'ratePercent' => '8', 'statute' => 'Ala. Code § 40-23-193',
        ]);
        // Texas republishes its single local rate every year, so the determination
        // EXPIRES. A supply dated past it must refuse rather than price with a
        // figure nobody published, or price as if unelected and charge the rates the
        // election replaced.
        $register->rule('us:TX', 'remote_seller_election', [
            'program' => 'Single Local Use Tax Rate', 'mechanic' => 'single_local_rate',
            'ratePercent' => '1.75', 'statute' => 'Tex. Tax Code § 151.0595',
        ], from: '2026-01-01', until: '2026-12-31');

        foreach (['KS' => 'destination', 'TX' => 'origin', 'CA' => 'mixed', 'CO' => 'destination'] as $state => $basis) {
            $register->rule('us:'.$state, 'sourcing', ['basis' => $basis]);
        }

        // THE FOUR STATES WITH NO SALES TAX AT ALL. Each publishes exactly one row —
        // untyped, 0%, exempt — and nothing else. That is the register's clearest
        // possible answer and it has to price as 0%, not refuse.
        foreach (['DE', 'MT', 'NH', 'OR'] as $state) {
            $register->rate('us:'.$state, '0', 'exempt');
        }

        // THE EXEMPT ROW THAT SITS UNDER EVERY ONE OF THESE RULES. The register files
        // a capped exemption twice over: a `goods.clothing` row at 0% exempt, and the
        // rule below capping it. Modelling only the rule made this fixture disagree
        // with the register in the one way that mattered — the suite billed 6.25% on
        // a Massachusetts coat's excess while a real store billed nothing, in all
        // three states, because the 0% row answered first.
        foreach (['MA', 'NY', 'RI'] as $state) {
            $register->rate('us:'.$state, '0', 'exempt', 'goods.clothing');
        }

        // The pair that reads as one field with opposite meanings.
        $register->rule('us:MA', 'price_exemption', [
            'category' => 'goods.clothing', 'capAmount' => '175.00',
            'capCurrency' => 'USD', 'above' => 'excess_taxable',
        ]);
        $register->rule('us:NY', 'price_exemption', [
            'category' => 'goods.clothing', 'capAmount' => '110.00',
            'capCurrency' => 'USD', 'above' => 'whole_item_taxable',
        ]);
        $register->rule('us:RI', 'price_exemption', [
            'category' => 'goods.clothing', 'capAmount' => '250.00',
            'capCurrency' => 'USD', 'above' => 'excess_taxable',
        ]);

        // A holiday inside a window and nothing outside it.
        $register->rule('us:TX', 'holiday', [
            'name' => 'Back-to-School', 'category' => 'goods.clothing',
            'capAmount' => '100.00', 'capCurrency' => 'USD', 'capIsExclusive' => true,
        ], from: '2026-08-07', until: '2026-08-09');
        $register->rule('us:OH', 'holiday', [
            'name' => 'Sales Tax Holiday', 'category' => 'goods.clothing',
            'capAmount' => '75.00', 'capCurrency' => 'USD', 'capIsExclusive' => false,
        ], from: '2026-08-01', until: '2026-08-14');
    }
}
