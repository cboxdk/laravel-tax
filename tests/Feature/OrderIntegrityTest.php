<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxOrder;

/*
 * The arithmetic of a whole order, where a shop meets it: discounts, credit notes,
 * freight split across rates.
 */

function frenchOrder(array $lines): TaxOrder
{
    $fr = app(JurisdictionRepository::class)->find(new CountryCode('FR'));

    return new TaxOrder($fr, CustomerType::Consumer, new SellerRegistrations(new CountryCode('FR'), [new SellerRegistration(new CountryCode('FR'))]), Pricing::Exclusive, $lines, suppliedAt: new DateTimeImmutable('2026-09-22'));
}

it('splits freight by what each rate actually sold, net of discounts', function (): void {
    // A food discount REDUCES the food share of freight. Weighted by absolute value it
    // added 50 to it instead: freight went 4.00 at 20% and 6.00 at 5.5%, where the
    // goods actually sold were 100 at 20% against 50 at 5.5% — 6.67 and 3.33, taxed 1.33 and 0.18.
    $a = app(OrderTaxCalculator::class)->assessOrder(frenchOrder([
        new SupplyLine('laptop', Money::of('100.00', 'EUR')),
        new SupplyLine('food', Money::of('100.00', 'EUR'), TaxClass::Groceries),
        new SupplyLine('food-discount', Money::of('-50.00', 'EUR'), TaxClass::Groceries),
        new SupplyLine('freight', Money::of('10.00', 'EUR'), isDeliveryCharge: true),
    ]));

    expect((string) $a->forLine('freight')->tax->getAmount())->toBe('1.51');
});

it('splits a credit note\'s freight the same way, signs and all', function (): void {
    $a = app(OrderTaxCalculator::class)->assessOrder(frenchOrder([
        new SupplyLine('laptop', Money::of('-100.00', 'EUR')),
        new SupplyLine('food', Money::of('-50.00', 'EUR'), TaxClass::Groceries),
        new SupplyLine('freight', Money::of('-10.00', 'EUR'), isDeliveryCharge: true),
    ]));

    expect((string) $a->forLine('freight')->tax->getAmount())->toBe('-1.51');
});

function massachusettsCoats(string $amount, int $quantity)
{
    $ma = app(JurisdictionRepository::class)->find(new CountryCode('US'), new SubdivisionCode('US-MA'));
    $seller = new SellerRegistrations(new CountryCode('US'), [new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-MA'))]);

    return app(OrderTaxCalculator::class)->assessOrder(new TaxOrder($ma, CustomerType::Consumer, $seller, Pricing::Exclusive, [
        new SupplyLine('coats', Money::of($amount, 'USD'), TaxClass::Clothing, quantity: $quantity),
    ], suppliedAt: new DateTimeImmutable('2026-09-22')))->forLine('coats');
}

it('applies a per-item price cap per item, not per line', function (): void {
    // Two $150 coats on one line are two coats under Massachusetts' $175 — not one
    // $300 coat taxed on $125.
    expect((string) massachusettsCoats('300.00', 2)->tax->getAmount())->toBe('0.00')
        // Two $200 coats: each taxed on its $25 excess, so $50 at 6.25%.
        ->and((string) massachusettsCoats('400.00', 2)->tax->getAmount())->toBe('3.13');
});

it('refuses a quantity below one', function (): void {
    new SupplyLine('x', Money::of('1.00', 'USD'), quantity: 0);
    massachusettsCoats('1.00', 0);
})->throws(InvalidArgumentException::class, 'at least 1');
