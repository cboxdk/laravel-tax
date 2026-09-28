<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;

/*
 * Colorado taxes no sale of 17 cents or less, Idaho none of 11, Maryland none of 19.
 * The register published it as a `minimum_taxable_sale` rule, and the engine read no
 * rule of that kind: the tax was charged anyway.
 */

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/minimum-sale');

    FakeRegister::at(config('tax.register.store'))
        ->rate('us:CO', '2.9', from: '2001-01-01')
        ->rule('us:CO', 'minimum_taxable_sale', ['exemptUpTo' => '0.17', 'currency' => 'USD'], from: '2001-01-01')
        ->install();
});

function coloradoSeller(): SellerRegistrations
{
    return new SellerRegistrations(new CountryCode('US'), [new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-CO'))]);
}

function coloradoSale(string $amount): TaxAssessment
{
    return app(TaxCalculator::class)->assess(new TaxQuery(
        amount: Money::of($amount, 'USD'),
        pricing: Pricing::Exclusive,
        place: app(JurisdictionRepository::class)->find(new CountryCode('US'), new SubdivisionCode('US-CO')),
        customer: CustomerType::Consumer,
        seller: coloradoSeller(),
        suppliedAt: new DateTimeImmutable('2026-09-28'),
    ));
}

it('taxes no sale at or under the minimum, and the whole of one a cent over', function (): void {
    $atLimit = coloradoSale('0.17');
    $over = coloradoSale('0.18');

    expect($atLimit->treatment)->toBe(TaxTreatment::Exempt)
        ->and((string) $atLimit->tax->getAmount())->toBe('0.00')
        ->and($atLimit->reason)->toContain('0.17')
        ->and($over->treatment)->toBe(TaxTreatment::Standard);
});

it('reads the minimum on the sale, not the line', function (): void {
    // Two 10-cent items are one 20-cent sale, over Colorado's 17 cents.
    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        app(JurisdictionRepository::class)->find(new CountryCode('US'), new SubdivisionCode('US-CO')),
        CustomerType::Consumer,
        coloradoSeller(),
        Pricing::Exclusive,
        [
            new SupplyLine('a', Money::of('0.10', 'USD')),
            new SupplyLine('b', Money::of('0.10', 'USD')),
        ],
        suppliedAt: new DateTimeImmutable('2026-09-28'),
    ));

    expect(array_map(fn (TaxAssessment $a): TaxTreatment => $a->treatment, $order->assessments()))->toBe([TaxTreatment::Standard, TaxTreatment::Standard]);
});
