<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;

/*
 * A STATE THAT WRITES ITS TAX AS A TABLE IS PRICED BY THE TABLE. Maryland's statute
 * states the tax as the table; Pennsylvania publishes one; neither is a member of the
 * Streamlined agreement, whose rule against requiring a bracket system is why the
 * other states round a percentage instead. The engine priced all four at the table's
 * per-dollar figure — within a cent — and flagged it everywhere.
 */

/** Pennsylvania's published 6% table, as the register carries it. */
function pennsylvaniaTable(): array
{
    $rows = [
        ['from' => '0.00', 'upTo' => '0.10', 'tax' => '0.00'],
        ['from' => '0.11', 'upTo' => '0.17', 'tax' => '0.01'],
        ['from' => '0.18', 'upTo' => '0.34', 'tax' => '0.02'],
        ['from' => '0.35', 'upTo' => '0.50', 'tax' => '0.03'],
        ['from' => '0.51', 'upTo' => '0.67', 'tax' => '0.04'],
        ['from' => '0.68', 'upTo' => '0.84', 'tax' => '0.05'],
        ['from' => '0.85', 'upTo' => '1.00', 'tax' => '0.06'],
    ];

    return ['basis' => 'bracket', 'percentage' => null, 'brackets' => [
        'rows' => $rows,
        'above' => ['perWholeUnit' => ['amount' => '0.06', 'currency' => 'USD', 'per' => 'dollar'], 'rows' => $rows],
    ]];
}

/** Maryland's: 6 cents on each exact dollar, then 1 cent from 1 cent, 2 from 17, 3 from 34… */
function marylandTable(): array
{
    // As the register carries it: the excess table starts at one cent.
    $excess = [
        ['from' => '0.01', 'upTo' => '0.16', 'tax' => '0.01'],
        ['from' => '0.17', 'upTo' => '0.33', 'tax' => '0.02'],
        ['from' => '0.34', 'upTo' => '0.50', 'tax' => '0.03'],
        ['from' => '0.51', 'upTo' => '0.66', 'tax' => '0.04'],
        ['from' => '0.67', 'upTo' => '0.83', 'tax' => '0.05'],
        ['from' => '0.84', 'upTo' => '0.99', 'tax' => '0.06'],
    ];

    return ['basis' => 'bracket', 'percentage' => null, 'brackets' => [
        'rows' => [
            ['from' => '0.00', 'upTo' => '0.19', 'tax' => '0.00'],
            ['from' => '0.20', 'upTo' => '0.20', 'tax' => '0.01'],
            ['from' => '0.21', 'upTo' => '0.33', 'tax' => '0.02'],
            ['from' => '0.34', 'upTo' => '0.50', 'tax' => '0.03'],
            ['from' => '0.51', 'upTo' => '0.66', 'tax' => '0.04'],
            ['from' => '0.67', 'upTo' => '0.83', 'tax' => '0.05'],
            ['from' => '0.84', 'upTo' => '1.00', 'tax' => '0.06'],
        ],
        'above' => ['perWholeUnit' => ['amount' => '0.06', 'currency' => 'USD', 'per' => 'dollar'], 'rows' => $excess],
    ]];
}

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/bracket-schedule');

    FakeRegister::at(config('tax.register.store'))
        ->rate('us:PA', '0', from: '1990-01-01', extra: pennsylvaniaTable())
        ->rate('us:PA:COUNTY-ALLEGHENY', '1', 'local_component', from: '1990-01-01')->named('us:PA:COUNTY-ALLEGHENY', 'Allegheny County')
        ->rate('us:MD', '0', from: '1990-01-01', extra: marylandTable())
        ->usLocal('PA', needs: 'county')
        ->install();
});

function sale(string $state, string $amount, Pricing $pricing = Pricing::Exclusive, ?string $county = null): TaxAssessment
{
    $place = app(JurisdictionRepository::class)->find(new CountryCode('US'), new SubdivisionCode($state));

    if ($county !== null) {
        $place = $place->withLocality(new LocalityCode(new SubdivisionCode($state), 'county', $county));
    }

    return app(TaxCalculator::class)->assess(new TaxQuery(
        amount: Money::of($amount, 'USD'),
        pricing: $pricing,
        place: $place,
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [new SellerRegistration(new CountryCode('US'), new SubdivisionCode($state))]),
        category: TaxClass::GeneralGoods,
        suppliedAt: new DateTimeImmutable('2026-09-28'),
    ));
}

it('prices by the table where six per cent rounded says otherwise', function (): void {
    // $1.34 in Maryland: six cents for the dollar, three for 34 cents. 6% is 8.04.
    $maryland = sale('US-MD', '1.34');
    // Under 20 cents Maryland taxes nothing; 6% of 19 cents rounds to a cent.
    $small = sale('US-MD', '0.19');
    // $2.10 in Pennsylvania: 12 cents for two dollars, nothing for 10 cents. 6% is 12.6.
    $pennsylvania = sale('US-PA', '2.10');

    expect((string) $maryland->tax->getAmount())->toBe('0.09')
        ->and($maryland->rate?->confidence)->toBe(Confidence::Authoritative)
        ->and($maryland->rate?->limitedBy)->toBeNull()
        ->and((string) $small->tax->getAmount())->toBe('0.00')
        ->and((string) $pennsylvania->tax->getAmount())->toBe('0.12');
});

it('reads the table on the sale, not line by line', function (): void {
    // Two lines at $0.40 in Maryland are one $0.80 sale: 5 cents, where the table
    // read per line gives 3 and 3.
    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        app(JurisdictionRepository::class)->find(new CountryCode('US'), new SubdivisionCode('US-MD')),
        CustomerType::Consumer,
        new SellerRegistrations(new CountryCode('US'), [new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-MD'))]),
        Pricing::Exclusive,
        [
            new SupplyLine('pen', Money::of('0.40', 'USD')),
            new SupplyLine('pencil', Money::of('0.40', 'USD')),
        ],
        suppliedAt: new DateTimeImmutable('2026-09-28'),
    ));

    expect((string) $order->tax()->getAmount())->toBe('0.05')
        ->and((string) $order->gross()->getAmount())->toBe('0.85');
});

it('falls back to the per-dollar figure, flagged, where the table cannot be read', function (): void {
    // A tax-inclusive price has two nets that land on the same total, and the table
    // cannot choose between them; a local share stacked on the state's table is a
    // total the state publishes its own table for, which the register does not carry.
    $inclusive = sale('US-MD', '10.00', Pricing::Inclusive);
    $allegheny = sale('US-PA', '10.00', county: 'Allegheny County');

    expect($inclusive->rate?->limitedBy)->toBe(RateLimit::BracketSchedule)
        ->and($inclusive->rate?->confidence)->toBe(Confidence::Derived)
        ->and((string) $allegheny->rate?->percentage)->toBe('7')
        ->and($allegheny->rate?->limitedBy)->toBe(RateLimit::BracketSchedule)
        ->and((string) $allegheny->tax->getAmount())->toBe('0.70');
});

it('adds nothing for the excess over an exact number of dollars', function (): void {
    // Maryland's excess table starts at one cent; $3.00 is three exact dollars.
    expect((string) sale('US-MD', '3.00')->tax->getAmount())->toBe('0.18')
        ->and(sale('US-MD', '3.00')->rate?->limitedBy)->toBeNull();
});

it('reads a credit on the same table, signed back', function (): void {
    expect((string) sale('US-MD', '-1.34')->tax->getAmount())->toBe('-0.09');
});
