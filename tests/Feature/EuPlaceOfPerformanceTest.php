<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\OssStatus;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

/*
 * Where a service is PERFORMED. A hotel room is taxed where the hotel is, an event
 * where it is held, a meal where it is served, a journey where it runs — for a
 * business customer and a consumer alike (Arts. 47, 48, 53, 54(1), 55). The engine
 * used the customer's country for these, and reverse-charged them to a business:
 * a German hotel billed a French company nothing and a French guest French VAT.
 */

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/place-of-performance');

    FakeRegister::at(config('tax.register.store'))
        ->category('services')->category('services.accommodation', 'services')->category('services.telecom', 'services')->category('services.restaurant', 'services')
        ->rate('eu:DE', '19', from: '1990-01-01')
        ->rate('eu:DE', '7', 'reduced', 'services.accommodation', from: '1990-01-01')
        ->rate('eu:FR', '20', from: '1990-01-01')
        ->rate('eu:FR', '10', 'reduced', 'services.accommodation', from: '1990-01-01')
        ->install();
});

function performed(TaxClass $class, CustomerType $customer, bool $validated = false, ?string $at = null, ?string $key = null, string $buyer = 'FR'): TaxQuery
{
    $geo = app(JurisdictionRepository::class);

    return new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $geo->find(new CountryCode($buyer)),
        customer: $customer,
        // A German supplier, registered for OSS so a destination answer is its own to collect.
        seller: new SellerRegistrations(new CountryCode('DE'), [new SellerRegistration(new CountryCode('DE'))], new OssStatus(registered: true)),
        category: $class,
        customerTaxIdValidated: $validated,
        suppliedAt: new DateTimeImmutable('2026-09-22'),
        categoryKey: $key,
        performedAt: $at === null ? null : $geo->find(new CountryCode($at)),
    );
}

it('taxes a hotel stay where the hotel is, for a business customer too', function (): void {
    // Not reverse-charged: a French company cannot self-account German VAT on a room
    // in Munich. German VAT, at Germany's rate for accommodation.
    $a = app(TaxCalculator::class)->assess(performed(TaxClass::Accommodation, CustomerType::Business, validated: true, at: 'DE'));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and($a->placeOfSupply->country->value)->toBe('DE')
        ->and((string) $a->tax->getAmount())->toBe('7.00');
});

it('taxes a hotel stay where the hotel is for a consumer, not where the guest lives', function (): void {
    expect((string) app(TaxCalculator::class)->assess(performed(TaxClass::Accommodation, CustomerType::Consumer, at: 'DE'))->tax->getAmount())->toBe('7.00')
        // ...and a German company's hotel in France is French VAT.
        ->and((string) app(TaxCalculator::class)->assess(performed(TaxClass::Accommodation, CustomerType::Consumer, at: 'FR'))->tax->getAmount())->toBe('10.00');
});

it('assumes the supplier\'s country when no place is given, and says so', function (): void {
    // The usual case — a German hotel company's German hotel — but an assumption, and
    // a chain with hotels abroad must pass where the room is.
    $a = app(TaxCalculator::class)->assess(performed(TaxClass::Accommodation, CustomerType::Consumer));

    expect($a->placeOfSupply->country->value)->toBe('DE')
        ->and($a->rate?->limitedBy)->toBe(RateLimit::PerformanceLocationAssumed);
});

it('treats a business customer without a validated number as a consumer for the general rule', function (): void {
    // Art. 45 for a non-taxable person: the supplier's establishment. Without a
    // validated number the supplier cannot treat the buyer as taxable, and charging
    // the buyer's country's VAT was neither answer.
    $a = app(TaxCalculator::class)->assess(performed(TaxClass::ProfessionalService, CustomerType::Business));

    expect($a->placeOfSupply->country->value)->toBe('DE')
        ->and((string) $a->tax->getAmount())->toBe('19.00');
});

it('gives postal and waste services the general rule, not the goods rule', function (TaxClass $class): void {
    // They fell to Art. 33(a), the rule for goods — taxed at the customer and eligible
    // for the micro-business relief meant for distance sales.
    expect(app(TaxCalculator::class)->assess(performed($class, CustomerType::Consumer))->placeOfSupply->country->value)->toBe('DE');
})->with([TaxClass::PostalService, TaxClass::WasteTreatment]);

it('reads the place-of-supply rule from a register key', function (): void {
    // A telecommunications service is taxed where the customer is (Art. 58) — the key
    // must not fall to the general services rule — and a meal where it is served.
    expect(app(TaxCalculator::class)->assess(performed(TaxClass::GeneralGoods, CustomerType::Consumer, key: 'services.telecom'))->placeOfSupply->country->value)->toBe('FR')
        ->and(app(TaxCalculator::class)->assess(performed(TaxClass::GeneralGoods, CustomerType::Consumer, at: 'DE', key: 'services.restaurant'))->placeOfSupply->country->value)->toBe('DE');
});
