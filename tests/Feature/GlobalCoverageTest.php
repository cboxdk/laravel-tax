<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

it('applies the primary-source-verified national rate', function (string $country, string $expectedTax) {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode($country)),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode($country)),
    ));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe($expectedTax);
})->with([
    'Taiwan 5%' => ['TW', '5.00'],
    'UAE 5%' => ['AE', '5.00'],
    'Saudi Arabia 15%' => ['SA', '15.00'],
    'Bahrain 10%' => ['BH', '10.00'],
    'Oman 5%' => ['OM', '5.00'],
    'Türkiye 20%' => ['TR', '20.00'],
    'Chile 19%' => ['CL', '19.00'],
    'Indonesia 11% effective' => ['ID', '11.00'],
    'Vietnam 10% standard' => ['VN', '10.00'],
    'Philippines 12%' => ['PH', '12.00'],
]);
