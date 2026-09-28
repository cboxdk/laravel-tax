<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\ExchangeRates;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\ExchangeRates\EcbExchangeRates;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Support\Facades\Http;

/*
 * An invoice in another currency than the country's must still state its tax in the
 * country's own — a Danish sale invoiced in euros owes Danish kroner, converted at the
 * rate in force when the tax became chargeable (Art. 91 of the VAT Directive). The
 * engine carried no rate at all, so every host converted on its own, at its own date.
 */

function ecbXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
  <gesmes:subject>Reference rates</gesmes:subject>
  <Cube>
    <Cube time="2026-09-25">
      <Cube currency="USD" rate="1.1700"/>
      <Cube currency="DKK" rate="7.4630"/>
      <Cube currency="SEK" rate="11.0500"/>
    </Cube>
    <Cube time="2026-09-24">
      <Cube currency="USD" rate="1.1650"/>
      <Cube currency="DKK" rate="7.4625"/>
    </Cube>
  </Cube>
</gesmes:Envelope>
XML;
}

beforeEach(function (): void {
    config()->set('tax.exchange_rates.store', sys_get_temp_dir().'/cbox-tax-fx-'.getmypid());
    Http::fake(['*' => Http::response(ecbXml())]);
    $this->artisan('tax:fx:sync')->assertSuccessful();

    app()->forgetInstance(ExchangeRates::class);
    app()->forgetInstance(TaxCalculator::class);
    app()->forgetInstance(OrderTaxCalculator::class);
});

it('reads the rate in force on a date, through the euro', function (): void {
    $rates = app(ExchangeRates::class);

    expect($rates)->toBeInstanceOf(EcbExchangeRates::class)
        ->and((string) $rates->rate('EUR', 'DKK', new DateTimeImmutable('2026-09-25'))?->rate)->toBe('7.463')
        // Sunday: Friday's publication is the one in force.
        ->and($rates->rate('EUR', 'DKK', new DateTimeImmutable('2026-09-27'))?->publishedOn->format('Y-m-d'))->toBe('2026-09-25')
        // Dollars to kroner is kroner per euro over dollars per euro.
        ->and((string) $rates->rate('USD', 'DKK', new DateTimeImmutable('2026-09-25'))?->rate)->toBe('6.3786324786')
        // Thursday's publication does not quote SEK: no rate, not an older one.
        ->and($rates->rate('EUR', 'SEK', new DateTimeImmutable('2026-09-24')))->toBeNull()
        // Two weeks on, the store is stale rather than on holiday.
        ->and($rates->rate('EUR', 'DKK', new DateTimeImmutable('2026-10-09')))->toBeNull();
});

it('states the tax of a euro invoice for a Danish sale in kroner', function (): void {
    $denmark = app(JurisdictionRepository::class)->find(new CountryCode('DK'));

    $assessment = app(TaxCalculator::class)->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $denmark,
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
        suppliedAt: new DateTimeImmutable('2026-09-25'),
    ));

    // 25.00 EUR at 7.4630 is 186.575 kroner, rounded half-up.
    expect((string) $assessment->tax)->toBe('EUR 25.00')
        ->and($assessment->exchangeRate?->source)->toBe('ecb')
        ->and((string) $assessment->taxInLocalCurrency())->toBe('DKK 186.58');

    $inKroner = app(TaxCalculator::class)->assess(new TaxQuery(
        amount: Money::of('100.00', 'DKK'),
        pricing: Pricing::Exclusive,
        place: $denmark,
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
        suppliedAt: new DateTimeImmutable('2026-09-25'),
    ));

    expect($inKroner->exchangeRate)->toBeNull()
        ->and($inKroner->taxInLocalCurrency())->toBeNull();
});

it('converts an order\'s tax once, from the total', function (): void {
    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        app(JurisdictionRepository::class)->find(new CountryCode('DK')),
        CustomerType::Consumer,
        new SellerRegistrations(new CountryCode('DK')),
        Pricing::Exclusive,
        [
            new SupplyLine('a', Money::of('0.02', 'EUR')),
            new SupplyLine('b', Money::of('0.02', 'EUR')),
            new SupplyLine('c', Money::of('0.02', 'EUR')),
        ],
        suppliedAt: new DateTimeImmutable('2026-09-25'),
    ));

    // Each line's 0.01 EUR converts to 0.07 DKK; the order's 0.03 EUR is 0.22 DKK, not 0.21.
    expect((string) $order->tax())->toBe('EUR 0.03')
        ->and((string) $order->taxInLocalCurrency())->toBe('DKK 0.22');
});
