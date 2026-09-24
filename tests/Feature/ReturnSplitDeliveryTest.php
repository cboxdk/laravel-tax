<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxOrder;

/*
 * A delivery charge shared between supplies taxed differently is ONE line on the
 * invoice and SEVERAL on a return. Filed as one, the zero-rated share of the freight
 * went into the standard box.
 */

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/split-delivery-return');

    FakeRegister::at(config('tax.register.store'))
        ->rate('europe:GB', '20', from: '1990-01-01')
        ->rate('europe:GB', '0', 'zero', 'goods.food.basic', from: '1990-01-01')
        ->install();
});

it('books each portion of a split delivery under its own treatment', function (): void {
    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        app(JurisdictionRepository::class)->find(new CountryCode('GB')),
        CustomerType::Consumer,
        new SellerRegistrations(new CountryCode('GB')),
        Pricing::Exclusive,
        [
            new SupplyLine('laptop', Money::of('100.00', 'GBP')),
            new SupplyLine('food', Money::of('100.00', 'GBP'), TaxClass::Groceries),
            new SupplyLine('freight', Money::of('10.00', 'GBP'), isDeliveryCharge: true),
        ],
        suppliedAt: new DateTimeImmutable('2026-09-22'),
    ));

    $line = app(ReturnAggregator::class)->aggregate($order->assessments())->lineFor(new CountryCode('GB'), 'GBP');

    expect((string) $line?->forTreatment(TaxTreatment::Standard)?->net->getAmount())->toBe('105.00')
        ->and((string) $line?->forTreatment(TaxTreatment::Standard)?->tax->getAmount())->toBe('21.00')
        ->and((string) $line?->forTreatment(TaxTreatment::ZeroRated)?->net->getAmount())->toBe('105.00')
        // The whole line still reconciles with the invoices behind it.
        ->and((string) $line?->net->getAmount())->toBe('210.00')
        ->and((string) $line?->tax->getAmount())->toBe((string) $order->tax()->getAmount());
});
