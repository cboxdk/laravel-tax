<?php

declare(strict_types=1);

use Brick\Money\Context\CashContext;
use Brick\Money\Context\CustomContext;
use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RoundingScope;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\ValueObjects\OrderAssessment;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxOrder;

// A plan at 49.00, 10% off for a bundle, and a 100% coupon on what is left comes to
// nothing. Rounded per line at 25% it comes to 12.25 - 1.23 - 11.03 = -0.01: VAT on
// a sale of nothing. Asked for invoice rounding, every regime that charges a plain
// percentage rounds the basket once, so the tax is the rate on the total.

/**
 * @param  list<SupplyLine>  $lines
 */
function basket(
    string $country,
    array $lines,
    RoundingScope $scope = RoundingScope::Invoice,
    Pricing $pricing = Pricing::Exclusive,
    ?string $subdivision = null,
    ?string $postalCode = null,
): OrderAssessment {
    $code = new CountryCode($country);
    $place = app(JurisdictionRepository::class)->find($code, $subdivision === null ? null : new SubdivisionCode($subdivision));

    return app(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        place: $place,
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations($code),
        pricing: $pricing,
        lines: $lines,
        suppliedAt: new DateTimeImmutable('2026-09-22'),
        postalCode: $postalCode,
        roundingScope: $scope,
    ));
}

/** @return list<SupplyLine> */
function discountedToNothing(string $currency = 'EUR'): array
{
    return [
        new SupplyLine('plan', Money::of('49.00', $currency)),
        new SupplyLine('bundle', Money::of('-4.90', $currency)),
        new SupplyLine('coupon', Money::of('-44.10', $currency)),
    ];
}

/** @return list<string> */
function lineTaxes(OrderAssessment $order): array
{
    $taxes = [];

    foreach ($order->lines as $line) {
        $taxes[] = (string) $line->assessment->tax->getAmount();
    }

    return $taxes;
}

it('leaves a discounted basket rounded per line unless invoice rounding is asked for', function (): void {
    $order = basket('DK', discountedToNothing('DKK'), RoundingScope::Line);

    expect(lineTaxes($order))->toBe(['12.25', '-1.23', '-11.03'])
        ->and((string) $order->tax()->getAmount())->toBe('-0.01')
        ->and($order->lines[0]->assessment->rounding)->toBeNull()
        ->and($order->lines[0]->assessment->unroundedTax)->toBeNull();
});

it('charges no tax on a basket discounted to nothing', function (string $country, string $currency, ?string $subdivision, array $amounts): void {
    $lines = [];

    foreach ($amounts as $i => $amount) {
        $lines[] = new SupplyLine((string) $i, Money::of($amount, $currency));
    }

    $byLine = basket($country, $lines, RoundingScope::Line, subdivision: $subdivision);
    $byInvoice = basket($country, $lines, subdivision: $subdivision);

    // Each basket is one line rounding gets wrong, or the case proves nothing.
    expect($byLine->net()->isZero())->toBeTrue()
        ->and($byLine->tax()->isZero())->toBeFalse()
        ->and($byInvoice->net()->isZero())->toBeTrue()
        ->and($byInvoice->tax()->isZero())->toBeTrue();
})->with([
    'EU, domestic at 25%' => ['DK', 'DKK', null, ['49.00', '-4.90', '-44.10']],
    'EU, 20% has no halves to round' => ['FR', 'EUR', null, ['49.02', '49.02', '49.02', '-147.06']],
    'national, UK' => ['GB', 'GBP', null, ['49.02', '49.02', '49.02', '-147.06']],
    'national, Norway' => ['NO', 'NOK', null, ['49.00', '-4.90', '-44.10']],
    'Canada, combined HST' => ['CA', 'CAD', 'CA-ON', ['49.05', '-12.26', '-36.79']],
    'India' => ['IN', 'INR', 'IN-KA', ['49.00', '-12.25', '-36.75']],
    'Malaysia' => ['MY', 'MYR', null, ['49.05', '-2.45', '-46.60']],
]);

it('makes the tax the rate on the total, not the sum of the rounded lines', function (string $country, string $currency, string $line, string $expected): void {
    $lines = [
        new SupplyLine('a', Money::of($line, $currency)),
        new SupplyLine('b', Money::of($line, $currency)),
        new SupplyLine('c', Money::of($line, $currency)),
    ];

    expect((string) basket($country, $lines)->tax()->getAmount())->toBe($expected);
})->with([
    // 0.33 at 25% is 0.0825: three lines at 0.08 are 0.24, the total's 0.99 is 0.25.
    'DK' => ['DK', 'DKK', '0.33', '0.25'],
    // 0.35 at 20% is 0.07 exactly, and stays so.
    'GB' => ['GB', 'GBP', '0.35', '0.21'],
    // 0.22 at 20% is 0.044: three lines at 0.04 are 0.12, the total's 0.66 is 0.13.
    'GB, a miss' => ['GB', 'GBP', '0.22', '0.13'],
]);

it('keeps a net price and shares the odd cent onto the largest remainder', function (): void {
    $order = basket('DK', [
        new SupplyLine('a', Money::of('0.34', 'DKK')),
        new SupplyLine('b', Money::of('0.33', 'DKK')),
        new SupplyLine('c', Money::of('0.33', 'DKK')),
    ]);

    expect((string) $order->net()->getAmount())->toBe('1.00')
        ->and((string) $order->lines[0]->assessment->net->getAmount())->toBe('0.34')
        ->and((string) $order->tax()->getAmount())->toBe('0.25')
        ->and((string) $order->gross()->getAmount())->toBe('1.25');

    foreach ($order->lines as $line) {
        expect((string) $line->assessment->gross->getAmount())
            ->toBe((string) $line->assessment->net->plus($line->assessment->tax)->getAmount());
    }
});

it('leaves a one-line invoice as line rounding would have it', function (string $pricing, string $amount): void {
    $mode = Pricing::from($pricing);
    $line = [new SupplyLine('a', Money::of($amount, 'EUR'))];
    $byLine = basket('DK', $line, RoundingScope::Line, $mode);
    $byInvoice = basket('DK', $line, RoundingScope::Invoice, $mode);

    expect((string) $byInvoice->tax()->getAmount())->toBe((string) $byLine->tax()->getAmount())
        ->and((string) $byInvoice->net()->getAmount())->toBe((string) $byLine->net()->getAmount())
        ->and((string) $byInvoice->gross()->getAmount())->toBe((string) $byLine->gross()->getAmount());
})->with([
    ['exclusive', '49.00'],
    ['exclusive', '0.02'],
    ['exclusive', '-4.90'],
    ['inclusive', '49.00'],
    ['inclusive', '99.99'],
]);

it('keeps the gross price on a tax-inclusive basket', function (): void {
    $order = basket('DK', [
        new SupplyLine('a', Money::of('10.01', 'DKK')),
        new SupplyLine('b', Money::of('10.01', 'DKK')),
        new SupplyLine('c', Money::of('-20.02', 'DKK')),
    ], pricing: Pricing::Inclusive);

    expect((string) $order->gross()->getAmount())->toBe('0.00')
        ->and((string) $order->tax()->getAmount())->toBe('0.00')
        ->and((string) $order->lines[0]->assessment->gross->getAmount())->toBe('10.01');
});

it('rounds to a currency with no minor unit', function (): void {
    $order = basket('DK', [
        new SupplyLine('a', Money::of('2', 'JPY')),
        new SupplyLine('b', Money::of('2', 'JPY')),
        new SupplyLine('c', Money::of('-4', 'JPY')),
    ]);

    expect((string) $order->tax()->getAmount())->toBe('0');
});

it('keeps line rounding for money a rounding policy cannot express', function (Money $amount): void {
    $line = [new SupplyLine('a', $amount)];

    expect((string) basket('DK', $line)->tax()->getAmount())
        ->toBe((string) basket('DK', $line, RoundingScope::Line)->tax()->getAmount());
})->with([
    'a cash step' => fn (): Money => Money::of('10.05', 'CHF', new CashContext(5)),
    'more places than a policy has' => fn (): Money => Money::of('10.123456789', 'EUR', new CustomContext(9)),
]);

it('rounds each rate on its own', function (): void {
    // Standard 0.66 at 20% is 0.132; books 0.60 at 5.5% is 0.033. Rounded together
    // the 0.165 would be 0.17 and belong to no rate on the VAT return.
    $order = basket('FR', [
        new SupplyLine('a', Money::of('0.22', 'EUR')),
        new SupplyLine('b', Money::of('0.22', 'EUR')),
        new SupplyLine('c', Money::of('0.22', 'EUR')),
        new SupplyLine('books', Money::of('0.60', 'EUR'), TaxClass::Book),
    ]);

    expect((string) $order->tax()->getAmount())->toBe('0.16')
        ->and((string) $order->lines[3]->assessment->tax->getAmount())->toBe('0.03');
});

it('rounds a territory on its own rate, not the mainland one it replaced', function (): void {
    // Madeira charges 22% where the mainland charges 23%. The exact tax kept for the
    // invoice must be Madeira's, or the basket is rounded on Portugal's figure.
    $order = basket('PT', [
        new SupplyLine('a', Money::of('1.00', 'EUR')),
        new SupplyLine('b', Money::of('1.00', 'EUR')),
        new SupplyLine('c', Money::of('1.00', 'EUR')),
    ], postalCode: '9000-000');

    expect((string) $order->lines[0]->assessment->rate?->percentage)->toBe('22')
        ->and((string) $order->tax()->getAmount())->toBe('0.66');
});

it('rounds a delivery charge with the goods it accompanies', function (): void {
    $order = basket('DK', [
        new SupplyLine('a', Money::of('0.33', 'DKK')),
        new SupplyLine('b', Money::of('0.33', 'DKK')),
        new SupplyLine('freight', Money::of('0.33', 'DKK'), isDeliveryCharge: true),
    ]);

    expect((string) $order->tax()->getAmount())->toBe('0.25');
});
