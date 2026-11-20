<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\MarginGoods;
use Cbox\Tax\Enums\MarginMethod;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\RefusalReason;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\DecisionFacts;
use Cbox\Tax\ValueObjects\MarginSale;
use Cbox\Tax\ValueObjects\OssStatus;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\SupplyRoute;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * The margin scheme, against an invented register that carries the shapes the real one
 * publishes: the goods' rate in the Netherlands, the standard rate forced in Germany,
 * an export exempt in the Union and taxed in the United Kingdom.
 */
beforeEach(function (): void {
    $register = FakeRegister::at(config('tax.register.store'), '2026.02.01-1');

    foreach (['NL' => '21', 'DE' => '19', 'FR' => '20', 'ES' => '21'] as $code => $rate) {
        $register->rate('eu:'.$code, $rate, from: '1990-01-01');
    }

    $register->rate('eu:NL', '9', 'reduced', 'goods.publications.book', from: '1990-01-01');
    $register->rate('eu:DE', '7', 'reduced', 'goods.publications.book', from: '1990-01-01');
    $register->rate('europe:GB', '20', from: '1990-01-01');
    $register->rate('europe:GB', '0', 'zero', 'goods.publications.book', from: '1990-01-01');

    $scheme = static fn (string $basis, array $more = []): array => [
        'appliesTo' => 'second_hand_goods', 'basis' => $basis, 'optional' => true, 'conditions' => [], ...$more,
    ];

    $register->rule('eu:NL', 'margin_scheme', $scheme('margin', [
        'rate' => ['applies' => 'goods'],
        'export' => ['treatment' => 'exempt'],
        'invoice' => ['mention' => 'Bijzondere regeling – gebruikte goederen', 'reference' => 'Artikel 35a, eerste lid, onderdeel p, Wet op de omzetbelasting 1968'],
    ]), '2020-01-01');
    $register->rule('eu:NL', 'margin_scheme', $scheme('global_accounting', [
        'rate' => ['applies' => 'goods'], 'export' => ['treatment' => 'exempt'],
        'invoice' => ['mention' => 'Bijzondere regeling – gebruikte goederen'],
    ]), '2020-01-01');
    $register->rule('eu:DE', 'margin_scheme', $scheme('margin', [
        'rate' => ['applies' => 'standard'],
        'export' => ['treatment' => 'exempt'],
        'invoice' => ['mention' => 'Gebrauchtgegenstände/Sonderregelung', 'reference' => '§ 14a Abs. 6 UStG'],
        'conditions' => [['kind' => 'excludes', 'says' => 'Edelsteine', 'names' => 'precious stones', 'codes' => ['7102', '7103']]],
    ]), '2020-01-01');
    $register->rule('eu:DE', 'margin_scheme', $scheme('global_accounting', [
        'rate' => ['applies' => 'standard'], 'export' => ['treatment' => 'exempt'],
        'conditions' => [['kind' => 'quantity_limit', 'says' => '750 Euro', 'names' => 'cap', 'operator' => 'at_most', 'amount' => '750', 'unit' => 'EUR']],
    ]), '2020-01-01');
    $register->rule('europe:GB', 'margin_scheme', $scheme('margin', [
        'rate' => ['applies' => 'goods'],
        'export' => ['treatment' => 'taxed', 'from' => '2023-05-01'],
    ]), '2020-01-01');
    // As the register published the scheme before it stated a rate.
    $register->rule('eu:ES', 'margin_scheme', $scheme('margin'), '2020-01-01');

    $register->install();
    $this->app->forgetInstance(RegisterData::class);

    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

function marginSale(string $seller, string $to, string $sold, string $bought, array $with = [], string $currency = 'EUR'): TaxQuery
{
    return new TaxQuery(...[
        'amount' => Money::of($sold, $currency),
        'pricing' => Pricing::Inclusive,
        'place' => test()->geo->find(new CountryCode($to)),
        'customer' => CustomerType::Consumer,
        'seller' => new SellerRegistrations(new CountryCode($seller), oss: new OssStatus(registered: true)),
        'margin' => new MarginSale(Money::of($bought, $currency)),
        ...$with,
    ]);
}

it('taxes the margin, VAT included, on a Dutch second-hand sale to a Dutch consumer', function (): void {
    $a = $this->tax->assess(marginSale('NL', 'NL', '30.00', '10.00'));

    expect($a->treatment)->toBe(TaxTreatment::MarginScheme)
        ->and((string) $a->tax->getAmount())->toBe('3.47') // 20 × 21/121
        ->and((string) $a->gross->getAmount())->toBe('30.00')
        ->and((string) $a->net->getAmount())->toBe('26.53')
        ->and((string) $a->taxableBase?->getAmount())->toBe('16.53')
        ->and($a->placeOfSupply->country->value)->toBe('NL')
        ->and((string) $a->margin?->margin->getAmount())->toBe('20.00')
        ->and($a->margin?->taxComputable)->toBeTrue()
        ->and($a->treatment->chargesTax())->toBeFalse()
        ->and($a->treatment->taxWasDue())->toBeTrue()
        ->and($a->mentions[0]->code)->toBe('margin_scheme')
        ->and($a->mentions[0]->text)->toBe('Bijzondere regeling – gebruikte goederen');
});

it('keeps a sale to a German consumer in the Netherlands: no distance sale, no OSS', function (): void {
    // The seller holds OSS, and an ordinary sale would be German VAT at 19%.
    $a = $this->tax->assess(marginSale('NL', 'DE', '30.00', '10.00'));

    expect($a->treatment)->toBe(TaxTreatment::MarginScheme)
        ->and($a->placeOfSupply->country->value)->toBe('NL')
        ->and((string) $a->tax->getAmount())->toBe('3.47')
        ->and($a->reason)->toContain('Arts. 35 and 139(3)')->toContain('EUR 10,000');
});

it('does not turn a margin sale to a business in another member state into an intra-Community supply', function (): void {
    $a = $this->tax->assess(marginSale('NL', 'DE', '30.00', '10.00', ['customer' => CustomerType::Business, 'customerTaxIdValidated' => true, 'customerTaxId' => 'DE123456789']));

    expect($a->treatment)->toBe(TaxTreatment::MarginScheme)
        ->and((string) $a->tax->getAmount())->toBe('3.47');
});

it('exempts a margin sale exported from the Union', function (): void {
    $a = $this->tax->assess(marginSale('NL', 'US', '30.00', '10.00'));

    expect($a->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($a->tax->isZero())->toBeTrue()
        ->and($a->placeOfSupply->country->value)->toBe('NL')
        ->and($a->reason)->toContain('Art. 321')
        ->and($a->mentions[0]->code)->toBe('export');
});

it('treats the Canary Islands as an export and Northern Ireland as inside the Union', function (): void {
    $canaries = $this->tax->assess(marginSale('NL', 'ES', '30.00', '10.00', ['postalCode' => '38001']));
    $belfast = $this->tax->assess(marginSale('NL', 'GB', '30.00', '10.00', ['postalCode' => 'BT1 1AA']));
    $london = $this->tax->assess(marginSale('NL', 'GB', '30.00', '10.00', ['postalCode' => 'SW1A 1AA']));

    expect($canaries->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($belfast->treatment)->toBe(TaxTreatment::MarginScheme)
        ->and((string) $belfast->tax->getAmount())->toBe('3.47')
        ->and($london->treatment)->toBe(TaxTreatment::ZeroRated);
});

it('owes nothing on a sale at a loss', function (): void {
    $a = $this->tax->assess(marginSale('NL', 'NL', '8.00', '10.00'));

    expect($a->treatment)->toBe(TaxTreatment::MarginScheme)
        ->and($a->tax->isZero())->toBeTrue()
        ->and((string) $a->margin?->margin->getAmount())->toBe('0.00')
        ->and((string) $a->net->getAmount())->toBe('8.00')
        ->and($a->reason)->toContain('offsets no other sale');
});

it('taxes a British margin of 20 pounds at one sixth', function (): void {
    $a = $this->tax->assess(marginSale('GB', 'GB', '50.00', '30.00', currency: 'GBP'));

    expect($a->treatment)->toBe(TaxTreatment::MarginScheme)
        ->and((string) $a->tax->getAmount())->toBe('3.33')
        ->and($a->placeOfSupply->country->value)->toBe('GB');
});

it('keeps the VAT on a British margin sale that is exported, where the Union would exempt it', function (): void {
    $a = $this->tax->assess(marginSale('GB', 'US', '50.00', '30.00', currency: 'GBP'));

    expect($a->treatment)->toBe(TaxTreatment::MarginScheme)
        ->and((string) $a->tax->getAmount())->toBe('3.33')
        ->and($a->reason)->toContain('does not relieve a margin sale that leaves its territory');
});

it('treats a sale from Northern Ireland to a member state as one inside the scheme', function (): void {
    $belfast = $this->geo->find(new CountryCode('GB'), new SubdivisionCode('GB-NIR'));
    $a = $this->tax->assess(marginSale('GB', 'FR', '50.00', '30.00', ['route' => new SupplyRoute(shipFrom: $belfast)], 'GBP'));

    expect($a->treatment)->toBe(TaxTreatment::MarginScheme)
        ->and((string) $a->tax->getAmount())->toBe('3.33')
        ->and($a->reason)->not->toContain('does not relieve');
});

it('reads the rate per state: the goods\' own in the Netherlands, the standard rate in Germany', function (): void {
    $book = ['category' => TaxClass::Book];

    $dutch = $this->tax->assess(marginSale('NL', 'NL', '30.00', '10.00', $book));
    $german = $this->tax->assess(marginSale('DE', 'DE', '30.00', '10.00', $book));
    $british = $this->tax->assess(marginSale('GB', 'GB', '30.00', '10.00', $book, 'GBP'));

    expect((string) $dutch->tax->getAmount())->toBe('1.65')   // 20 × 9/109
        ->and((string) $german->tax->getAmount())->toBe('3.19') // 20 × 19/119, not 7/107
        ->and((string) $german->rate?->percentage)->toBe('19')
        ->and($british->tax->isZero())->toBeTrue()              // zero-rated goods, zero-rated margin
        ->and($british->treatment)->toBe(TaxTreatment::MarginScheme);
});

it('gives no figure for one sale under the per-period method', function (): void {
    $a = $this->tax->assess(marginSale('NL', 'NL', '30.00', '10.00', ['margin' => new MarginSale(Money::of('10.00', 'EUR'), MarginGoods::SecondHandGoods, MarginMethod::Global)]));

    expect($a->treatment)->toBe(TaxTreatment::MarginScheme)
        ->and($a->tax->isZero())->toBeTrue()
        ->and($a->margin?->taxComputable)->toBeFalse()
        ->and($a->limitedBy)->toBe(RateLimit::MarginPerPeriod)
        ->and($a->rate)->toBeNull()
        ->and($a->reason)->toContain('no VAT figure of its own');
});

it('credits exactly what the sale charged', function (): void {
    $a = $this->tax->assess(marginSale('NL', 'NL', '-30.00', '10.00'));

    expect((string) $a->tax->getAmount())->toBe('-3.47')
        ->and((string) $a->gross->getAmount())->toBe('-30.00');
});

it('refuses what it would have to guess', function (callable $query, RefusalReason $reason, string $says): void {
    try {
        $this->tax->assess($query());
        $this->fail('Expected a refusal.');
    } catch (UnresolvedTaxRule $e) {
        expect($e->reason())->toBe($reason)->and($e->getMessage())->toContain($says);
    }
})->with([
    'a service' => [fn (): TaxQuery => marginSale('NL', 'NL', '30.00', '10.00', ['category' => TaxClass::DigitalService]), RefusalReason::MarginSchemeIneligible, 'is a service'],
    'an item bought with deductible VAT' => [fn (): TaxQuery => marginSale('NL', 'NL', '30.00', '10.00', ['facts' => new DecisionFacts(['margin.acquiredWithoutDeductibleVat' => false])]), RefusalReason::MarginSchemeIneligible, 'without VAT it can deduct'],
    'a state with no published scheme' => [fn (): TaxQuery => marginSale('FR', 'FR', '30.00', '10.00'), RefusalReason::MarginSchemeUnavailable, 'publishes no margin scheme'],
    'a class of goods the state does not publish' => [fn (): TaxQuery => marginSale('NL', 'NL', '30.00', '10.00', ['margin' => new MarginSale(Money::of('10.00', 'EUR'), MarginGoods::Antiques)]), RefusalReason::MarginSchemeUnavailable, 'antiques'],
    'a rule that does not say its rate' => [fn (): TaxQuery => marginSale('ES', 'ES', '30.00', '10.00'), RefusalReason::MarginSchemeUnavailable, 'which rate the margin bears'],
    'goods the state shuts out' => [fn (): TaxQuery => marginSale('DE', 'DE', '30.00', '10.00', ['commodityCode' => '71023100']), RefusalReason::MarginSchemeIneligible, '7102'],
    'a purchase above the cap for the per-period method' => [fn (): TaxQuery => marginSale('DE', 'DE', '900.00', '800.00', ['margin' => new MarginSale(Money::of('800.00', 'EUR'), MarginGoods::SecondHandGoods, MarginMethod::Global)]), RefusalReason::MarginSchemeIneligible, '750'],
    'goods shipped from a state the seller is not registered in' => [fn (): TaxQuery => marginSale('NL', 'DE', '30.00', '10.00', ['route' => new SupplyRoute(shipFrom: test()->geo->find(new CountryCode('DE')))]), RefusalReason::MarginSchemeUnavailable, 'neither established nor registered'],
]);

it('refuses a purchase price in another currency than the sale', function (): void {
    marginSale('NL', 'NL', '30.00', '10.00', ['margin' => new MarginSale(Money::of('10.00', 'USD'))]);
})->throws(InvalidArgumentException::class);

it('taxes margin and ordinary lines of one order each under its own rules, and delivery with the margin sale', function (): void {
    $order = new TaxOrder(
        place: $this->geo->find(new CountryCode('DE')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('NL'), oss: new OssStatus(registered: true)),
        pricing: Pricing::Inclusive,
        lines: [
            new SupplyLine('used', Money::of('30.00', 'EUR'), margin: new MarginSale(Money::of('10.00', 'EUR'))),
            new SupplyLine('new', Money::of('119.00', 'EUR')),
        ],
    );

    $assessed = $this->app->make(OrderTaxCalculator::class)->assessOrder($order);
    $used = $assessed->lines[0]->assessment;
    $new = $assessed->lines[1]->assessment;

    expect($used->treatment)->toBe(TaxTreatment::MarginScheme)
        ->and($used->placeOfSupply->country->value)->toBe('NL')
        ->and((string) $used->tax->getAmount())->toBe('3.47')
        ->and($new->treatment)->toBe(TaxTreatment::Standard)
        ->and($new->placeOfSupply->country->value)->toBe('DE')
        ->and((string) $new->tax->getAmount())->toBe('19.00');

    $delivered = $this->app->make(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        place: $this->geo->find(new CountryCode('DE')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('NL'), oss: new OssStatus(registered: true)),
        pricing: Pricing::Inclusive,
        lines: [
            new SupplyLine('used', Money::of('30.00', 'EUR'), margin: new MarginSale(Money::of('10.00', 'EUR'))),
            new SupplyLine('post', Money::of('12.10', 'EUR'), isDeliveryCharge: true),
        ],
    ));

    // Delivery is part of the selling price: all of it margin, at the Dutch rate.
    expect((string) $delivered->lines[1]->assessment->tax->getAmount())->toBe('2.10')
        ->and($delivered->lines[1]->assessment->portions[0]->placeOfSupply->country->value)->toBe('NL');
});

it('refuses a British margin export from before the day the register dates its treatment', function (): void {
    try {
        $this->tax->assess(marginSale('GB', 'US', '50.00', '30.00', ['suppliedAt' => new DateTimeImmutable('2023-04-30')], 'GBP'));
        $this->fail('Expected a refusal.');
    } catch (UnresolvedTaxRule $e) {
        expect($e->reason())->toBe(RefusalReason::MarginSchemeUnavailable)->and($e->getMessage())->toContain('2023-04-30');
    }
});
