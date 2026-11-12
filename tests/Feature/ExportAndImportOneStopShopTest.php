<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\ExchangeRates;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\ValueObjects\ExchangeRate;
use Cbox\Tax\ValueObjects\InvoiceMention;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\SupplyRoute;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;

/*
 * Goods crossing the Union's border, in both directions.
 *
 * OUT: an EU shop's goods shipped to a customer outside the Union are an exempt export
 * from its own state (Art. 146(1)(a)/(b) of the VAT Directive). The engine answered
 * them as `NotRegistered` in the destination, which charged the right nothing and
 * filed it nowhere: the line belongs in the seller's own return as an export, and the
 * invoice has to cite the exemption (Art. 226(11)).
 *
 * IN: an IOSS registration collects the customer's VAT at checkout, but only on a
 * consignment whose intrinsic value is at most EUR 150, and never on excise goods
 * (Art. 369l). Above that the customer pays import VAT at the border, so charging it
 * at checkout as well taxed the goods twice.
 */

function borderPlace(string $country, ?string $subdivision = null): Jurisdiction
{
    return app(JurisdictionRepository::class)->find(new CountryCode($country), $subdivision === null ? null : new SubdivisionCode($subdivision))
        ?? throw new RuntimeException("No jurisdiction {$country}");
}

function borderQuery(SellerRegistrations $seller, string $buyer, string $amount = '100.00', string $currency = 'EUR', TaxClass $class = TaxClass::GeneralGoods, ?string $subdivision = null, SupplyRoute $route = new SupplyRoute, Pricing $pricing = Pricing::Exclusive): TaxQuery
{
    return new TaxQuery(
        amount: Money::of($amount, $currency),
        pricing: $pricing,
        place: borderPlace($buyer, $subdivision),
        customer: CustomerType::Consumer,
        seller: $seller,
        category: $class,
        suppliedAt: new DateTimeImmutable('2026-09-22'),
        route: $route,
    );
}

function dutchShop(string ...$registeredIn): SellerRegistrations
{
    return new SellerRegistrations(new CountryCode('NL'), array_map(fn (string $cc): SellerRegistration => new SellerRegistration(new CountryCode($cc)), $registeredIn));
}

function londonShopWithIoss(): SellerRegistrations
{
    return new SellerRegistrations(new CountryCode('GB'), [
        new SellerRegistration(new CountryCode('GB')),
        new SellerRegistration(new CountryCode('IE'), scheme: 'ioss', validFrom: new DateTimeImmutable('2026-01-01')),
    ]);
}

/** A fixed GBP→EUR rate, as the ECB would publish it. */
function bindPoundRate(?string $rate): void
{
    app()->instance(ExchangeRates::class, new readonly class($rate) implements ExchangeRates
    {
        public function __construct(private ?string $gbp) {}

        public function rate(string $from, string $to, DateTimeImmutable $on): ?ExchangeRate
        {
            return $this->gbp !== null && $from === 'GBP' && $to === 'EUR'
                ? new ExchangeRate('GBP', 'EUR', BigDecimal::of($this->gbp), $on, 'ecb')
                : null;
        }
    });

    app()->forgetInstance(TaxCalculator::class);
    app()->forgetInstance(OrderTaxCalculator::class);
}

// --- Exports -------------------------------------------------------------------

it('answers an EU shop\'s goods shipped outside the Union as an exempt export from its own state', function (string $buyer, ?string $subdivision): void {
    $a = app(TaxCalculator::class)->assess(borderQuery(dutchShop(), $buyer, subdivision: $subdivision));

    expect($a->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($a->placeOfSupply->country->value)->toBe('NL')
        ->and((string) $a->tax->getAmount())->toBe('0.00')
        ->and((string) $a->gross->getAmount())->toBe('100.00')
        ->and($a->reason)->toContain('Art. 146')
        ->and($a->reason)->toContain($buyer)
        ->and($a->mentions)->toHaveCount(1)
        ->and($a->mentions[0]->code)->toBe('export')
        ->and($a->mentions[0]->reference)->toContain('Article 146(1)');
})->with([
    'the United Kingdom' => ['GB', null],
    'Norway' => ['NO', null],
    'Switzerland' => ['CH', null],
    'New York' => ['US', 'US-NY'],
]);

it('treats the delivery on an exported order as part of the export', function (): void {
    $order = new TaxOrder(
        place: borderPlace('CH'),
        customer: CustomerType::Consumer,
        seller: dutchShop(),
        pricing: Pricing::Exclusive,
        lines: [
            new SupplyLine('lp', Money::of('100.00', 'EUR')),
            new SupplyLine('shipping', Money::of('15.00', 'EUR'), isDeliveryCharge: true),
        ],
        suppliedAt: new DateTimeImmutable('2026-09-22'),
    );

    $assessed = app(OrderTaxCalculator::class)->assessOrder($order);

    expect($assessed->forLine('lp')?->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($assessed->forLine('shipping')?->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($assessed->forLine('shipping')?->placeOfSupply->country->value)->toBe('NL')
        ->and((string) $assessed->tax()->getAmount())->toBe('0.00');
});

it('still charges the destination\'s tax where the EU shop is registered there', function (): void {
    $a = app(TaxCalculator::class)->assess(borderQuery(dutchShop('NO'), 'NO'));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('25.00');
});

it('does not call it an export when the goods never leave from inside the Union', function (): void {
    // A Dutch shop's US warehouse shipping to a New York customer: a sale inside the
    // United States, which nothing in Art. 146 reaches.
    $a = app(TaxCalculator::class)->assess(borderQuery(dutchShop(), 'US', subdivision: 'US-NY', route: new SupplyRoute(shipFrom: borderPlace('US', 'US-NJ'))));

    expect($a->treatment)->toBe(TaxTreatment::NotRegistered);
});

it('leaves services where they are: an export is of goods', function (): void {
    $a = app(TaxCalculator::class)->assess(borderQuery(dutchShop(), 'GB', class: TaxClass::DigitalService));

    expect($a->treatment)->not->toBe(TaxTreatment::ZeroRated)
        ->and(array_map(static fn (InvoiceMention $m): string => $m->code, $a->mentions))->not->toContain('export');
});

it('does not make a non-EU shop an EU exporter', function (): void {
    $a = app(TaxCalculator::class)->assess(borderQuery(new SellerRegistrations(new CountryCode('US')), 'GB'));

    expect($a->treatment)->toBe(TaxTreatment::NotRegistered);
});

// --- IOSS and the EUR 150 consignment ------------------------------------------------

it('collects under IOSS on a consignment up to EUR 150, and says so', function (string $amount, string $tax): void {
    $a = app(TaxCalculator::class)->assess(borderQuery(londonShopWithIoss(), 'DE', $amount));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and($a->placeOfSupply->country->value)->toBe('DE')
        ->and((string) $a->tax->getAmount())->toBe($tax)
        ->and($a->reason)->toContain('IOSS')
        ->and($a->reason)->toContain('Art. 369l');
})->with([
    'EUR 100' => ['100.00', '19.00'],
    'exactly EUR 150' => ['150.00', '28.50'],
]);

it('leaves a consignment over EUR 150 to import VAT at the border', function (): void {
    $a = app(TaxCalculator::class)->assess(borderQuery(londonShopWithIoss(), 'DE', '400.00'));

    expect($a->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($a->placeOfSupply->country->value)->toBe('DE')
        ->and((string) $a->tax->getAmount())->toBe('0.00')
        ->and($a->reason)->toContain('Art. 369l')
        ->and($a->reason)->toContain('EUR 400.00')
        ->and($a->reason)->toContain('import');
});

it('measures the consignment across the order\'s goods, without the delivery', function (): void {
    $order = fn (array $lines): TaxOrder => new TaxOrder(
        place: borderPlace('DE'),
        customer: CustomerType::Consumer,
        seller: londonShopWithIoss(),
        pricing: Pricing::Exclusive,
        lines: $lines,
        suppliedAt: new DateTimeImmutable('2026-09-22'),
    );

    // Two EUR 100 records are one EUR 200 consignment: neither is IOSS's.
    $over = app(OrderTaxCalculator::class)->assessOrder($order([
        new SupplyLine('a', Money::of('100.00', 'EUR')),
        new SupplyLine('b', Money::of('100.00', 'EUR')),
        new SupplyLine('shipping', Money::of('20.00', 'EUR'), isDeliveryCharge: true),
    ]));

    expect($over->forLine('a')?->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($over->forLine('b')?->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($over->forLine('shipping')?->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and((string) $over->tax()->getAmount())->toBe('0.00');

    // EUR 140 of goods and EUR 20 of delivery: the delivery is not intrinsic value,
    // so the consignment is within the limit and the whole order is taxed, delivery
    // included (it is part of the taxable amount, Art. 78(b)).
    $within = app(OrderTaxCalculator::class)->assessOrder($order([
        new SupplyLine('a', Money::of('140.00', 'EUR')),
        new SupplyLine('shipping', Money::of('20.00', 'EUR'), isDeliveryCharge: true),
    ]));

    expect($within->forLine('a')?->treatment)->toBe(TaxTreatment::Standard)
        ->and($within->forLine('shipping')?->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $within->tax()->getAmount())->toBe('30.40');
});

it('converts a consignment in another currency to euro at the rate for the tax date', function (string $amount, TaxTreatment $treatment): void {
    bindPoundRate('1.17');

    $a = app(TaxCalculator::class)->assess(borderQuery(londonShopWithIoss(), 'DE', $amount, 'GBP'));

    expect($a->treatment)->toBe($treatment)
        ->and($a->reason)->toContain('EUR');
})->with([
    'GBP 120 is EUR 140.40' => ['120.00', TaxTreatment::Standard],
    'GBP 130 is EUR 152.10' => ['130.00', TaxTreatment::NotRegistered],
]);

it('collects, and flags it, when no rate can measure the consignment in euro', function (): void {
    bindPoundRate(null);

    $a = app(TaxCalculator::class)->assess(borderQuery(londonShopWithIoss(), 'DE', '100.00', 'GBP'));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and($a->limitedBy)->toBe(RateLimit::ConsignmentValueUnmeasured);
});

it('measures a tax-inclusive price without its VAT', function (): void {
    // EUR 170 including 19% is EUR 142.86 of goods: within the limit.
    $a = app(TaxCalculator::class)->assess(borderQuery(londonShopWithIoss(), 'DE', '170.00', pricing: Pricing::Inclusive));

    expect($a->treatment)->toBe(TaxTreatment::Standard);
});

it('keeps excise goods out of IOSS', function (): void {
    $a = app(TaxCalculator::class)->assess(borderQuery(londonShopWithIoss(), 'DE', '40.00', class: TaxClass::Wine));

    expect($a->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($a->reason)->toContain('excise');
});

it('does not read IOSS as covering services or goods already inside the Union', function (): void {
    $service = app(TaxCalculator::class)->assess(borderQuery(londonShopWithIoss(), 'DE', class: TaxClass::DigitalService));
    $warehoused = app(TaxCalculator::class)->assess(borderQuery(londonShopWithIoss(), 'DE', route: new SupplyRoute(shipFrom: borderPlace('NL'))));

    expect($service->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($service->reason)->toContain('IOSS')
        ->and($warehoused->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($warehoused->reason)->toContain('IOSS');
});

it('does not let an OSS registration carry an imported parcel past the IOSS limit', function (): void {
    // A UK shop holding both: OSS for its services, IOSS for its parcels. The parcel
    // over EUR 150 is still the border's; the one under is still IOSS's; and a service
    // is OSS's.
    $both = new SellerRegistrations(new CountryCode('GB'), [
        new SellerRegistration(new CountryCode('GB')),
        new SellerRegistration(new CountryCode('IE'), scheme: 'ioss', validFrom: new DateTimeImmutable('2026-01-01')),
        new SellerRegistration(new CountryCode('IE'), scheme: 'oss', validFrom: new DateTimeImmutable('2026-01-01')),
    ]);

    expect(app(TaxCalculator::class)->assess(borderQuery($both, 'DE', '400.00'))->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and(app(TaxCalculator::class)->assess(borderQuery($both, 'DE', '100.00'))->treatment)->toBe(TaxTreatment::Standard)
        ->and(app(TaxCalculator::class)->assess(borderQuery($both, 'DE', class: TaxClass::DigitalService))->treatment)->toBe(TaxTreatment::Standard)
        // Goods already in an EU warehouse are a distance sale inside the Union: OSS's.
        ->and(app(TaxCalculator::class)->assess(borderQuery($both, 'DE', '400.00', route: new SupplyRoute(shipFrom: borderPlace('NL'))))->treatment)->toBe(TaxTreatment::Standard);
});
