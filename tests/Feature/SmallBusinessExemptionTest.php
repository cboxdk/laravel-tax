<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RefusalReason;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\Refusal;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\MarginSale;
use Cbox\Tax\ValueObjects\OssStatus;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SmallBusinessExemption;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * The small-business exemption, against an invented register shaped like the real one:
 * the Dutch KOR at EUR 20,000 with the Union cap for sellers from other member states,
 * and a German scheme published for its own sellers only.
 */
beforeEach(function (): void {
    $register = FakeRegister::at(config('tax.register.store'), '2026.02.01-1');

    foreach (['NL' => '21', 'DE' => '19', 'FR' => '20'] as $code => $rate) {
        $register->rate('eu:'.$code, $rate, from: '1990-01-01');
    }

    $register->rate('europe:GB', '20', from: '1990-01-01');
    $register->rate('us:TX', '6.25');

    $register->rule('eu:NL', 'small_business_exemption', [
        'threshold' => ['amount' => '20000.00', 'currency' => 'EUR', 'measuredOver' => 'calendar_year'],
        'crossing' => ['endsWith' => 'crossing_supply', 'excludes' => 'rest_of_year_and_following_year'],
        'crossBorder' => ['unionTurnover' => ['amount' => '100000.00', 'currency' => 'EUR'], 'identification' => 'EX'],
        'invoice' => ['mention' => 'Vrijgesteld van omzetbelasting: kleineondernemersregeling', 'reference' => 'Artikel 25a Wet op de omzetbelasting 1968'],
        'citation' => 'artikel 25a Wet op de omzetbelasting 1968',
        'optional' => true,
    ], '2025-01-01');
    $register->rule('eu:DE', 'small_business_exemption', [
        'threshold' => ['amount' => '25000.00', 'currency' => 'EUR', 'measuredOver' => 'previous_calendar_year'],
        'citation' => '§ 19 UStG',
    ], '2025-01-01');
    $register->rule('eu:NL', 'margin_scheme', [
        'appliesTo' => 'second_hand_goods', 'basis' => 'margin', 'optional' => true, 'conditions' => [],
        'rate' => ['applies' => 'goods'], 'export' => ['treatment' => 'exempt'],
    ], '2020-01-01');

    $register->install();
    $this->app->forgetInstance(RegisterData::class);

    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

function kor(string $turnover = '0.00', string $in = 'NL', ?string $number = null, bool $unionExceeded = false): SmallBusinessExemption
{
    return new SmallBusinessExemption(new CountryCode($in), Money::of($turnover, 'EUR'), $unionExceeded, $number);
}

function smallSale(string $seller, string $to, array $claims, array $with = [], ?OssStatus $oss = null, string $amount = '30.00'): TaxQuery
{
    return new TaxQuery(...[
        'amount' => Money::of($amount, 'EUR'),
        'pricing' => Pricing::Inclusive,
        'place' => test()->geo->find(new CountryCode($to)),
        'customer' => CustomerType::Consumer,
        'seller' => new SellerRegistrations(new CountryCode($seller), oss: $oss, smallBusiness: $claims),
        ...$with,
    ]);
}

it('charges no VAT on a Dutch KOR seller\'s sale, with the exemption on the invoice', function (): void {
    $a = $this->tax->assess(smallSale('NL', 'NL', [kor('5000.00')]));

    expect($a->treatment)->toBe(TaxTreatment::Exempt)
        ->and($a->tax->isZero())->toBeTrue()
        ->and((string) $a->net->getAmount())->toBe('30.00')
        ->and($a->placeOfSupply->country->value)->toBe('NL')
        ->and($a->mentions[0]->code)->toBe('small_business_exemption')
        ->and($a->mentions[0]->reference)->toBe('Artikel 25a Wet op de omzetbelasting 1968')
        ->and($a->reason)->toContain('Small-business exemption in NL')->toContain('5030.00');
});

it('taxes the sale that passes the threshold, and every sale after it', function (): void {
    $last = $this->tax->assess(smallSale('NL', 'NL', [kor('19970.00')]));         // 20,000.00 exactly: still within
    $crossing = $this->tax->assess(smallSale('NL', 'NL', [kor('19990.00')]));     // 20,020.00
    $after = $this->tax->assess(smallSale('NL', 'NL', [kor('20020.00')]));

    expect($last->treatment)->toBe(TaxTreatment::Exempt)
        ->and($crossing->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $crossing->tax->getAmount())->toBe('5.21') // 30 × 21/121
        ->and($crossing->reason)->toContain('ends with the supply that passes it')
        ->and($after->treatment)->toBe(TaxTreatment::Standard);
});

it('measures the whole sale, not one line of it, against the threshold', function (): void {
    $assessed = $this->app->make(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        place: $this->geo->find(new CountryCode('NL')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('NL'), smallBusiness: [kor('19950.00')]),
        pricing: Pricing::Inclusive,
        lines: [new SupplyLine('a', Money::of('30.00', 'EUR')), new SupplyLine('b', Money::of('30.00', 'EUR'))],
    ));

    expect($assessed->lines[0]->assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and($assessed->lines[1]->assessment->treatment)->toBe(TaxTreatment::Standard);
});

it('does not let a refund push the seller over', function (): void {
    $a = $this->tax->assess(smallSale('NL', 'NL', [kor('19990.00')], amount: '-30.00'));

    expect($a->treatment)->toBe(TaxTreatment::Exempt);
});

it('exempts goods sent to a business in another member state at home, not under Art. 138', function (): void {
    $a = $this->tax->assess(smallSale('NL', 'DE', [kor('5000.00')], ['customer' => CustomerType::Business, 'customerTaxIdValidated' => true, 'customerTaxId' => 'DE123456789']));

    expect($a->treatment)->toBe(TaxTreatment::Exempt)
        ->and($a->placeOfSupply->country->value)->toBe('NL')
        ->and($a->reason)->toContain('Art. 139(1)')
        ->and($a->mentions[0]->code)->toBe('small_business_exemption');
});

it('leaves a service a business in another member state accounts for itself', function (): void {
    $a = $this->tax->assess(smallSale('NL', 'DE', [kor('5000.00')], ['customer' => CustomerType::Business, 'customerTaxIdValidated' => true, 'category' => TaxClass::DigitalService]));

    expect($a->treatment)->toBe(TaxTreatment::ReverseCharge);
});

it('follows the place of supply for a distance sale: exempt at home below EUR 10,000, the destination\'s VAT above it', function (): void {
    $below = $this->tax->assess(smallSale('NL', 'DE', [kor('5000.00')], oss: new OssStatus(registered: false, thresholdExceeded: false)));
    $above = $this->tax->assess(smallSale('NL', 'DE', [kor('5000.00')], oss: new OssStatus(registered: true)));

    expect($below->treatment)->toBe(TaxTreatment::Exempt)
        ->and($below->placeOfSupply->country->value)->toBe('NL')
        ->and($above->treatment)->toBe(TaxTreatment::Standard)
        ->and($above->placeOfSupply->country->value)->toBe('DE');
});

it('exempts an export and a margin sale alike: the seller charges no VAT at home at all', function (): void {
    $export = $this->tax->assess(smallSale('NL', 'US', [kor('5000.00')], ['place' => $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-TX'))]));
    $margin = $this->tax->assess(smallSale('NL', 'NL', [kor('5000.00')], ['margin' => new MarginSale(Money::of('10.00', 'EUR'))]));

    expect($export->treatment)->toBe(TaxTreatment::Exempt)
        ->and($export->placeOfSupply->country->value)->toBe('NL')
        ->and($margin->treatment)->toBe(TaxTreatment::Exempt)
        ->and($margin->margin)->toBeNull();
});

it('applies another member state\'s exemption to a seller with an EX number and Union turnover within the cap', function (): void {
    $oss = new OssStatus(registered: true);

    $exempt = $this->tax->assess(smallSale('DE', 'NL', [kor('5000.00', 'NL', 'DE123456789-EX')], oss: $oss));
    $over = $this->tax->assess(smallSale('DE', 'NL', [kor('5000.00', 'NL', 'DE123456789-EX', unionExceeded: true)], oss: $oss));

    expect($exempt->treatment)->toBe(TaxTreatment::Exempt)
        ->and($exempt->placeOfSupply->country->value)->toBe('NL')
        ->and($over->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $over->tax->getAmount())->toBe('5.21')
        ->and($over->reason)->toContain('Union turnover has passed EUR 100000.00');
});

it('refuses a claim the seller cannot have', function (callable $query, string $says): void {
    try {
        $this->tax->assess($query());
        $this->fail('Expected a refusal.');
    } catch (Refusal $e) {
        expect($e->reason())->toBe(RefusalReason::SmallBusinessExemptionUnavailable)->and($e->getMessage())->toContain($says);
    }
})->with([
    'a seller established in the United Kingdom' => [fn (): TaxQuery => smallSale('GB', 'NL', [kor('0.00')], oss: new OssStatus(registered: true)), 'established outside the Union'],
    'a state the register publishes no exemption for' => [fn (): TaxQuery => smallSale('FR', 'FR', [kor('0.00', 'FR')]), 'publishes no such exemption'],
    'another member state without the EX number' => [fn (): TaxQuery => smallSale('DE', 'NL', [kor('0.00', 'NL')], oss: new OssStatus(registered: true)), 'suffix EX'],
    'another member state with an ordinary VAT number' => [fn (): TaxQuery => smallSale('DE', 'NL', [kor('0.00', 'NL', 'DE123456789')], oss: new OssStatus(registered: true)), 'suffix EX'],
    'a state that publishes its scheme for its own sellers only' => [fn (): TaxQuery => smallSale('NL', 'DE', [kor('0.00', 'DE', 'NL123456789B01EX')], oss: new OssStatus(registered: true)), 'established in another member state'],
]);

it('refuses to compare a turnover in another currency with the threshold', function (): void {
    $claim = new SmallBusinessExemption(new CountryCode('NL'), Money::of('1000.00', 'USD'));

    try {
        $this->tax->assess(smallSale('NL', 'NL', [$claim]));
        $this->fail('Expected a refusal.');
    } catch (Refusal $e) {
        expect($e->reason())->toBe(RefusalReason::ThresholdCurrencyUnknown);
    }
});

it('honours the window the seller holds the exemption for', function (): void {
    $claim = new SmallBusinessExemption(new CountryCode('NL'), Money::of('0.00', 'EUR'), validFrom: new DateTimeImmutable('2026-01-01'), validUntil: new DateTimeImmutable('2026-06-30'));

    $inside = $this->tax->assess(smallSale('NL', 'NL', [$claim], ['suppliedAt' => new DateTimeImmutable('2026-03-01')]));
    $outside = $this->tax->assess(smallSale('NL', 'NL', [$claim], ['suppliedAt' => new DateTimeImmutable('2026-07-01')]));

    expect($inside->treatment)->toBe(TaxTreatment::Exempt)
        ->and($outside->treatment)->toBe(TaxTreatment::Standard);
});

it('keeps a seller out for the year after it passed the threshold, where the state says so', function (): void {
    $claim = new SmallBusinessExemption(new CountryCode('NL'), Money::of('100.00', 'EUR'), previousYearTurnover: Money::of('20500.00', 'EUR'));

    $a = $this->tax->assess(smallSale('NL', 'NL', [$claim]));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and($a->reason)->toContain('previous calendar year');
});
