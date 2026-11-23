<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RefusalReason;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\Refusal;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SmallBusinessExemption;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * The shapes the member states give the small-business exemption beyond the Dutch one,
 * against an invented register: a tolerance that keeps the year (Austria), a crossing
 * supply that stays exempt (Luxembourg), only the excess taxed (Latvia), a limit on the
 * previous year beside this year's (Germany), limits by the share of goods (Ireland),
 * France's services limit without food on the premises and lodging, two years shut
 * out, and a state that has none (Spain). Figures are the law's; every
 * sale is EUR 30.00 VAT-inclusive.
 */
beforeEach(function (): void {
    $register = FakeRegister::at(config('tax.register.store'), '2026.02.01-1');

    foreach (['AT' => '20', 'LU' => '17', 'LV' => '21', 'DE' => '19', 'IE' => '23', 'GR' => '24', 'ES' => '21', 'DK' => '25', 'FR' => '20'] as $code => $rate) {
        $register->rate('eu:'.$code, $rate, from: '1990-01-01');
    }

    $limit = static fn (string $amount, string $over = 'calendar_year', array $more = []): array => ['amount' => $amount, 'currency' => 'EUR', 'measuredOver' => $over, 'appliesTo' => 'all', ...$more];
    $rule = static fn (array $thresholds, string $crossing = 'crossing_supply', int $following = 1): array => [
        'available' => true,
        'thresholds' => $thresholds,
        'crossing' => ['endsWith' => $crossing, 'excludesFollowingYears' => $following],
        'invoice' => ['taxShown' => false],
        'citation' => 'the Act',
    ];

    $register->rule('eu:AT', 'small_business_exemption', $rule([$limit('55000.00', 'previous_calendar_year'), $limit('55000.00', 'calendar_year', ['tolerance' => ['amount' => '60500.00']])]), '2025-01-01');
    $register->rule('eu:LU', 'small_business_exemption', $rule([$limit('50000.00', 'calendar_year', ['tolerance' => ['amount' => '55000.00']])], 'after_crossing_supply'), '2025-01-01');
    $register->rule('eu:LV', 'small_business_exemption', $rule([$limit('50000.00', 'calendar_year', ['tolerance' => ['amount' => '55000.00']])], 'excess_only'), '2025-01-01');
    $register->rule('eu:DE', 'small_business_exemption', $rule([$limit('25000.00', 'previous_calendar_year'), $limit('100000.00')]), '2025-01-01');
    $register->rule('eu:IE', 'small_business_exemption', $rule([
        $limit('85000.00', 'calendar_year', ['whenGoodsShare' => ['atLeast' => '90']]),
        $limit('42500.00', 'calendar_year', ['whenGoodsShare' => ['below' => '90']]),
    ]), '2025-01-01');
    $register->rule('eu:GR', 'small_business_exemption', $rule([$limit('10000.00')], following: 2), '2025-01-01');
    $register->rule('eu:DK', 'small_business_exemption', $rule([$limit('50000.00'), $limit('350000.00', 'calendar_year', ['appliesTo' => 'first_sales_of_works_of_art'])]), '2025-01-01');
    $register->rule('eu:FR', 'small_business_exemption', $rule([
        $limit('85000.00', 'previous_calendar_year'),
        $limit('93500.00'),
        $limit('37500.00', 'previous_calendar_year', ['appliesTo' => 'services_other_than_food_and_lodging']),
        $limit('41250.00', 'calendar_year', ['appliesTo' => 'services_other_than_food_and_lodging']),
    ]), '2025-01-01');
    $register->rule('eu:ES', 'small_business_exemption', ['available' => false, 'says' => 'Spain has no franchise regime.', 'citation' => 'Ley 37/1992'], '2025-01-01');

    $register->install();
    $this->app->forgetInstance(RegisterData::class);

    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

function variantSale(string $in, string $turnover, ?string $previous = '0.00', ?string $services = null, ?string $twoYearsAgo = null, ?string $sector = null): TaxQuery
{
    $claim = new SmallBusinessExemption(
        new CountryCode($in),
        Money::of($turnover, 'EUR'),
        previousYearTurnover: $previous === null ? null : Money::of($previous, 'EUR'),
        twoYearsAgoTurnover: $twoYearsAgo === null ? null : Money::of($twoYearsAgo, 'EUR'),
        servicesTurnover: $services === null ? null : Money::of($services, 'EUR'),
        sector: $sector,
    );

    return new TaxQuery(
        amount: Money::of('30.00', 'EUR'),
        pricing: Pricing::Inclusive,
        place: test()->geo->find(new CountryCode($in)),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode($in), smallBusiness: [$claim]),
    );
}

it('keeps the year exempt within an Art. 288a tolerance and taxes the supply past it (Austria)', function (): void {
    $within = $this->tax->assess(variantSale('AT', '55000.00'));     // 55,030: over 55,000, within 60,500
    $past = $this->tax->assess(variantSale('AT', '60490.00'));       // 60,520
    $lastYear = $this->tax->assess(variantSale('AT', '0.00', '56000.00'));

    expect($within->treatment)->toBe(TaxTreatment::Exempt)
        ->and($within->reason)->toContain('tolerance of EUR 60500.00')
        ->and($past->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $past->tax->getAmount())->toBe('5.00') // 30 × 20/120
        ->and($lastYear->treatment)->toBe(TaxTreatment::Standard)
        ->and($lastYear->reason)->toContain('previous calendar year');
});

it('leaves the crossing supply exempt where tax starts with the next one (Luxembourg)', function (): void {
    $crossing = $this->tax->assess(variantSale('LU', '54990.00'));   // 55,020
    $next = $this->tax->assess(variantSale('LU', '55020.00'));

    expect($crossing->treatment)->toBe(TaxTreatment::Exempt)
        ->and($crossing->reason)->toContain('still exempt')
        ->and($next->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $next->tax->getAmount())->toBe('4.36'); // 30 × 17/117
});

it('taxes only the part of the crossing supply above the ceiling (Latvia)', function (): void {
    $a = $this->tax->assess(variantSale('LV', '54990.00'));          // 20.00 of 30.00 is above 55,000

    expect((string) $a->tax->getAmount())->toBe('3.47')                // 20 × 21/121
        ->and((string) $a->net->getAmount())->toBe('26.53')
        ->and($a->reason)->toContain('only that part of it is taxed');
});

it('holds the previous year and this year to their own limits (Germany)', function (): void {
    $lastYear = $this->tax->assess(variantSale('DE', '0.00', '30000.00'));
    $thisYear = $this->tax->assess(variantSale('DE', '99990.00', '20000.00'));
    $under = $this->tax->assess(variantSale('DE', '5000.00', '20000.00'));

    expect($lastYear->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $lastYear->tax->getAmount())->toBe('4.79') // 30 × 19/119
        ->and($thisYear->treatment)->toBe(TaxTreatment::Standard)
        ->and($under->treatment)->toBe(TaxTreatment::Exempt);
});

it('picks the limit by the share of goods, and refuses without the services figure (Ireland)', function (): void {
    $goods = $this->tax->assess(variantSale('IE', '42490.00', services: '0.00'));
    $mixed = $this->tax->assess(variantSale('IE', '42490.00', services: '20000.00'));

    expect($goods->treatment)->toBe(TaxTreatment::Exempt)
        ->and($mixed->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $mixed->tax->getAmount())->toBe('5.61'); // 30 × 23/123

    expect(fn () => $this->tax->assess(variantSale('IE', '1000.00')))
        ->toThrow('turnover in services was not given');
});

it('holds a sector to its own limit instead of the general one (Denmark\'s artists)', function (): void {
    $artist = $this->tax->assess(variantSale('DK', '100000.00', sector: 'first_sales_of_works_of_art'));
    $dealer = $this->tax->assess(variantSale('DK', '100000.00'));

    expect($artist->treatment)->toBe(TaxTreatment::Exempt)
        ->and($dealer->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $dealer->tax->getAmount())->toBe('6.00'); // 30 × 25/125
});

it('shuts a seller out for two years where the state says so', function (): void {
    $a = $this->tax->assess(variantSale('GR', '0.00', '0.00', twoYearsAgo: '12000.00'));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('5.81') // 30 × 24/124
        ->and($a->reason)->toContain('two calendar years ago');
});

it('refuses a claim in a state that has no exemption, and a limit measured on a year it was not given', function (): void {
    foreach ([[variantSale('ES', '0.00'), 'ES has no small-business exemption'], [variantSale('DE', '0.00', null), 'previous calendar year']] as [$query, $says]) {
        try {
            $this->tax->assess($query);
            $this->fail('Expected a refusal.');
        } catch (Refusal $e) {
            expect($e->reason())->toBe(RefusalReason::SmallBusinessExemptionUnavailable)->and($e->getMessage())->toContain($says);
        }
    }
});

it('leaves food served on the premises and lodging out of the services limit (France)', function (): void {
    $sale = function (string $turnover, string $services, ?string $foodAndLodging): TaxQuery {
        $claim = new SmallBusinessExemption(
            new CountryCode('FR'),
            Money::of($turnover, 'EUR'),
            previousYearTurnover: Money::of('0.00', 'EUR'),
            servicesTurnover: Money::of($services, 'EUR'),
            previousYearServicesTurnover: Money::of('0.00', 'EUR'),
            foodAndLodgingTurnover: $foodAndLodging === null ? null : Money::of($foodAndLodging, 'EUR'),
            previousYearFoodAndLodgingTurnover: Money::of('0.00', 'EUR'),
        );

        return new TaxQuery(
            amount: Money::of('30.00', 'EUR'),
            pricing: Pricing::Inclusive,
            place: $this->geo->find(new CountryCode('FR')),
            customer: CustomerType::Consumer,
            seller: new SellerRegistrations(new CountryCode('FR'), smallBusiness: [$claim]),
        );
    };

    $hotel = $this->tax->assess($sale('60000.00', '60000.00', '55000.00'));     // 5,000 other services
    $consultant = $this->tax->assess($sale('41240.00', '41240.00', '0.00'));   // 41,270 with the sale

    expect($hotel->treatment)->toBe(TaxTreatment::Exempt)
        ->and($consultant->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $consultant->tax->getAmount())->toBe('5.00'); // 30 × 20/120

    expect(fn () => $this->tax->assess($sale('1000.00', '1000.00', null)))
        ->toThrow('services (other than food served on the premises and lodging) turnover');
});
