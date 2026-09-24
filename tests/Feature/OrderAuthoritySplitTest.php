<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\LocalityScheme;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxOrder;

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/authority-split');

    FakeRegister::at(config('tax.register.store'))
        ->rate('us:KS', '6.5', from: '1990-01-01')
        ->rate('us:KS', '4', 'reduced', 'goods.food', from: '1990-01-01')
        ->rate('us:KS:COUNTY-209', '1', 'local_component', from: '1990-01-01')
        ->rate('us:KS:CITY-36000', '1.625', 'local_component', from: '1990-01-01')
        ->boundary('KS', '66101', ['state:20', 'county:209', 'city:36000'])
        ->rule('us:KS', 'taxable_base', ['component' => 'transport_on_taxable_goods', 'included' => true], '1990-01-01')
        ->install();
});

it('splits tax per authority even when freight is shared across rates', function (): void {
    // A US cart with two rates and shipping: the freight is split across both, and
    // the split line carries no single breakdown — each share has its own. The
    // roll-up gave up on the whole document, so exactly the carts a remittance
    // needs to split had none.
    $place = app(JurisdictionRepository::class)->find(new CountryCode('US'), new SubdivisionCode('US-KS'))
        ->withLocality(new LocalityCode(new SubdivisionCode('US-KS'), LocalityScheme::Zip9->value, '66101-1366'));
    $seller = new SellerRegistrations(new CountryCode('US'), [new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-KS'))]);

    $a = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder($place, CustomerType::Consumer, $seller, Pricing::Exclusive, [
        new SupplyLine('laptop', Money::of('100.00', 'USD')),
        new SupplyLine('groceries', Money::of('100.00', 'USD'), TaxClass::Groceries),
        new SupplyLine('freight', Money::of('10.00', 'USD'), isDeliveryCharge: true),
    ], suppliedAt: new DateTimeImmutable('2026-09-22')));

    $split = $a->taxByAuthority();
    $sum = array_reduce($split ?? [], static fn (?Money $carry, $t) => $carry === null ? $t->tax : $carry->plus($t->tax));

    expect(count($a->forLine('freight')->portions))->toBe(2)
        ->and($split)->not->toBeNull()
        ->and((string) $sum?->getAmount())->toBe((string) $a->tax()->getAmount());
});

it('files a split delivery by its portions, not as one line in the first place', function (): void {
    // The same cart, aggregated for a return. The freight line's own authority split
    // is null — each share has its own — and the aggregator read only the parent, so
    // the whole jurisdiction's authority totals came out unknown. It files each
    // portion now, which is what the invoice rounding already reconciles.
    $place = app(JurisdictionRepository::class)->find(new CountryCode('US'), new SubdivisionCode('US-KS'))
        ->withLocality(new LocalityCode(new SubdivisionCode('US-KS'), LocalityScheme::Zip9->value, '66101-1366'));
    $seller = new SellerRegistrations(new CountryCode('US'), [new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-KS'))]);
    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder($place, CustomerType::Consumer, $seller, Pricing::Exclusive, [
        new SupplyLine('laptop', Money::of('100.00', 'USD')),
        new SupplyLine('groceries', Money::of('100.00', 'USD'), TaxClass::Groceries),
        new SupplyLine('freight', Money::of('10.00', 'USD'), isDeliveryCharge: true),
    ], suppliedAt: new DateTimeImmutable('2026-09-22')));

    $return = app(ReturnAggregator::class)->aggregate($order->assessments());
    $line = $return->lineFor(new CountryCode('US'), 'USD', new SubdivisionCode('US-KS'));
    $sum = array_reduce($line?->authorities ?? [], static fn (?Money $carry, $t) => $carry === null ? $t->tax : $carry->plus($t->tax));

    expect($line?->authorities)->not->toBeNull()
        ->and((string) $sum?->getAmount())->toBe((string) $order->tax()->getAmount())
        ->and((string) $line?->tax->getAmount())->toBe((string) $order->tax()->getAmount())
        ->and((string) $line?->net->getAmount())->toBe('210.00');
});
