<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Exceptions\UnknownCategory;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\RateSource\ChainTaxRateSource;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;

/*
 * Asking about a category the register publishes and the enum cannot name.
 *
 * The register publishes 182 categories and TaxClass reaches 47. 103 of the rest carry
 * live rates — education in 77 jurisdictions, insurance in 67, restaurant service in
 * 36, and every medical-equipment line US states exempt one by one — and none of them
 * could be asked about. A query now names the register's key.
 */

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/category-keys');

    FakeRegister::at(config('tax.register.store'))
        ->category('goods')->category('services')
        ->category('goods.clothing', 'goods')->category('goods.clothing.childrens', 'goods.clothing')
        ->category('goods.medical_equipment', 'goods')->category('goods.medical_equipment.prosthetic', 'goods.medical_equipment')
        ->category('services.education', 'services')
        ->rate('eu:DK', '25', from: '1990-01-01')
        ->rate('eu:DK', '0', 'exempt', 'services.education', from: '1990-01-01')
        ->rate('us:KS', '6.5', from: '1990-01-01')
        ->rate('us:KS', '0', 'exempt', 'goods.medical_equipment.prosthetic', from: '1990-01-01')
        ->rate('us:MA', '6.25', from: '1990-01-01')
        ->rate('us:MA', '0', 'exempt', 'goods.clothing', from: '1990-01-01')
        ->rule('us:MA', 'price_exemption', ['category' => 'goods.clothing', 'capAmount' => '175.00', 'capCurrency' => 'USD', 'above' => 'excess_taxable'])
        ->install();
});

function keyQuery(string $country, ?string $state, string $amount, ?string $key, TaxClass $class = TaxClass::GeneralGoods): TaxQuery
{
    $geo = app(JurisdictionRepository::class);
    $place = $state === null ? $geo->find(new CountryCode($country)) : $geo->find(new CountryCode($country), new SubdivisionCode($state));
    $registration = $state === null ? new SellerRegistration(new CountryCode($country)) : new SellerRegistration(new CountryCode($country), new SubdivisionCode($state));

    return new TaxQuery(
        amount: Money::of($amount, $country === 'DK' ? 'DKK' : 'USD'),
        pricing: Pricing::Exclusive,
        place: $place,
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode($country), [$registration]),
        category: $class,
        suppliedAt: new DateTimeImmutable('2026-09-22'),
        categoryKey: $key,
    );
}

it('prices an education service the enum has no word for', function (): void {
    // Exempt in every EU member state under Article 132. Asked as the nearest class the
    // enum has, it was a professional service at 25%.
    $keyed = app(TaxCalculator::class)->assess(keyQuery('DK', null, '1000.00', 'services.education'));
    $classed = app(TaxCalculator::class)->assess(keyQuery('DK', null, '1000.00', null, TaxClass::ProfessionalService));

    expect((string) $keyed->tax->getAmount())->toBe('0.00')
        ->and((string) $classed->tax->getAmount())->toBe('250.00');
});

it('prices a prosthetic separately from medical equipment in general', function (): void {
    // Kansas exempts prosthetic devices by name. MedicalDevice reached only the parent.
    $keyed = app(TaxCalculator::class)->assess(keyQuery('US', 'US-KS', '100.00', 'goods.medical_equipment.prosthetic'));
    $classed = app(TaxCalculator::class)->assess(keyQuery('US', 'US-KS', '100.00', null, TaxClass::MedicalDevice));

    expect((string) $keyed->tax->getAmount())->toBe('0.00')
        ->and((string) $classed->tax->getAmount())->toBe('6.50');
});

it('applies a price cap filed at a parent to a key beneath it', function (): void {
    // Massachusetts caps `goods.clothing`. A child's coat is still clothing: matched up
    // the ladder, the $175 cap applies and the $25 excess is taxed.
    $a = app(TaxCalculator::class)->assess(keyQuery('US', 'US-MA', '200.00', 'goods.clothing.childrens'));

    expect((string) $a->tax->getAmount())->toBe('1.56');
});

it('refuses a key the installed release does not publish, and names what is nearby', function (): void {
    // The check an enum cannot make: a typo, or a real key a pinned release lacks.
    try {
        app(TaxCalculator::class)->assess(keyQuery('DK', null, '100.00', 'services.educaton'));
        $this->fail('An unpublished key must be refused.');
    } catch (UnknownCategory $e) {
        expect($e->getMessage())->toContain('services.educaton')->toContain('services.education');
    }
});

it('derives the governing class from the key, and lets an explicit class win', function (): void {
    expect(keyQuery('DK', null, '1.00', 'goods.clothing.childrens')->category)->toBe(TaxClass::Clothing)
        ->and(keyQuery('DK', null, '1.00', 'goods.medical_equipment.prosthetic')->category)->toBe(TaxClass::MedicalDevice)
        // No class sits under `services.education`, so the Directive's general B2C
        // rule governs — Article 45, the supplier's establishment.
        ->and(keyQuery('DK', null, '1.00', 'services.education')->category)->toBe(TaxClass::ProfessionalService)
        ->and(keyQuery('DK', null, '1.00', 'services.education', TaxClass::CulturalAdmission)->category)->toBe(TaxClass::CulturalAdmission);
});

it('carries the key from an order line to its delivery share', function (): void {
    $q = keyQuery('US', 'US-KS', '100.00', null);
    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder($q->place, $q->customer, $q->seller, Pricing::Exclusive, [
        new SupplyLine('leg', Money::of('100.00', 'USD'), categoryKey: 'goods.medical_equipment.prosthetic'),
    ], suppliedAt: $q->suppliedAt));

    expect((string) $order->forLine('leg')->tax->getAmount())->toBe('0.00');
});

it('lets a chain pass a key to the source that can answer it, and skips one that cannot', function (): void {
    // A host's own source in front, keyed on its own vocabulary. Asked about the class
    // the key maps to, it would answer a broader question as though it were this one.
    $own = new class implements TaxRateSource
    {
        public function rateFor(Jurisdiction $jurisdiction, TaxClass $category, ?DateTimeImmutable $at = null): ?TaxRate
        {
            return new TaxRate('99');
        }
    };

    $chain = new ChainTaxRateSource([$own, app(TaxRateSource::class)]);

    expect((string) $chain->rateForKey(keyQuery('DK', null, '1.00', null)->place, 'services.education')?->percentage)->toBe('0')
        ->and((string) $chain->rateFor(keyQuery('DK', null, '1.00', null)->place, TaxClass::GeneralGoods)?->percentage)->toBe('99');
});

it('refuses a key when the bound source cannot answer one', function (): void {
    app()->instance(TaxRateSource::class, new class implements TaxRateSource
    {
        public function rateFor(Jurisdiction $jurisdiction, TaxClass $category, ?DateTimeImmutable $at = null): ?TaxRate
        {
            return new TaxRate('25');
        }
    });
    app()->forgetInstance(TaxCalculator::class);

    app(TaxCalculator::class)->assess(keyQuery('DK', null, '100.00', 'services.education'));
})->throws(UnresolvedTaxRule::class, 'cannot answer a register category key');
