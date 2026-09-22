<?php

declare(strict_types=1);

use Brick\Money\Context\CustomContext;
use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\DeliveryComponent;
use Cbox\Tax\Enums\NexusCombinator;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RoundingScope;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\DeliveryCharge;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\SupplyRoute;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/rule-regressions');
    $this->rules = FakeRegister::at(config('tax.register.store'));
    foreach (['PA' => '6', 'AZ' => '5.6', 'IL' => '6.25', 'KS' => '6.5', 'CA' => '7.25'] as $state => $rate) {
        $this->rules->rate('us:'.$state, $rate, from: '1990-01-01');
    }

    $this->rules->rate('us:PA:ALLEGHENY', '1', 'local_component', from: '1990-01-01')->named('us:PA:ALLEGHENY', 'Allegheny County');
    $this->rules->rate('us:PA:PHILADELPHIA', '2', 'local_component', from: '1990-01-01')->named('us:PA:PHILADELPHIA', 'Philadelphia');
    $this->rules->rule('us:PA', 'sourcing', ['basis' => 'origin'], '2019-07-01', '2025-12-31');
    $this->rules->rule('us:PA', 'sourcing', ['basis' => 'destination'], '2026-01-01');
    $this->rules->rule('us:CA', 'sourcing', ['basis' => 'mixed']);
    foreach ([['200000.00', '2019-10-01', '2019-12-31'], ['150000.00', '2020-01-01', '2020-12-31'], ['100000.00', '2021-01-01', null]] as [$amount, $from, $until]) {
        $this->rules->rule('us:AZ', 'threshold', ['amount' => $amount, 'currency' => 'USD', 'binds' => 'remote_seller'], $from, $until);
    }
    $this->rules->rule('us:IL', 'rounding', ['method' => 'up', 'places' => 2, 'appliesTo' => 'invoice', 'aggregatesLocal' => true], '2000-07-07');
    $this->rules->rule('us:KS', 'rounding', ['method' => 'half_up', 'places' => 2, 'appliesTo' => 'seller_election', 'aggregatesLocal' => true]);
    $this->rules->rule('us:KS', 'taxable_base', ['component' => 'transport_on_taxable_goods', 'included' => false], '2026-09-14');
    $this->rules->rule('us:KS', 'taxable_base', ['component' => 'handling_on_taxable_goods', 'included' => true], '2026-09-14');
    $this->rules->rule('us:IL', 'taxable_base', ['component' => 'transport_on_taxable_goods', 'included' => true]);
    $this->rules->install();
    Http::preventStrayRequests();
});

function ruleQuery(string $state, string $amount = '1.00', Pricing $pricing = Pricing::Exclusive, string $date = '2026-09-18', bool $registered = true): TaxQuery
{
    $country = new CountryCode('US');
    $subdivision = new SubdivisionCode('US-'.$state);

    return new TaxQuery(
        amount: Money::of($amount, 'USD'),
        pricing: $pricing,
        place: app(JurisdictionRepository::class)->find($country, $subdivision),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations($country, $registered ? [new SellerRegistration($country, $subdivision)] : []),
        suppliedAt: new DateTimeImmutable($date),
    );
}

/** @param list<SupplyLine> $lines */
function ruleOrder(string $state, array $lines, Pricing $pricing = Pricing::Exclusive, RoundingScope $scope = RoundingScope::Line): TaxOrder
{
    $query = ruleQuery($state, pricing: $pricing);

    return new TaxOrder($query->place, $query->customer, $query->seller, $pricing, $lines, suppliedAt: $query->suppliedAt, roundingScope: $scope);
}

it('selects nexus records by inclusive effective windows and dates the advisory hint', function (string $date, ?int $expected): void {
    $threshold = app(NexusThresholds::class)->for(new SubdivisionCode('US-AZ'), new DateTimeImmutable($date));
    $assessment = app(TaxCalculator::class)->assess(ruleQuery('AZ', date: $date, registered: false));
    expect($threshold?->salesDollars)->toBe($expected);

    if ($expected === null) {
        expect($assessment->reason)->not->toContain('Economic-nexus');
    } else {
        expect($assessment->reason)->toContain('$'.number_format($expected));
    }
})->with([
    ['2019-09-30', null], ['2019-10-01', 200000], ['2019-12-31', 200000],
    ['2020-01-01', 150000], ['2020-12-31', 150000], ['2021-01-01', 100000], ['2026-09-18', 100000],
]);

it('changes the actual intrastate taxing location at the published transition', function (string $date, string $tax): void {
    $q = ruleQuery('PA', '100.00', date: $date);
    $state = new SubdivisionCode('US-PA');
    $assessment = app(TaxCalculator::class)->assess(new TaxQuery(
        $q->amount, $q->pricing,
        $q->place->withLocality(new LocalityCode($state, 'county', 'Philadelphia')),
        $q->customer, $q->seller,
        suppliedAt: $q->suppliedAt,
        route: new SupplyRoute(shipFrom: $q->place->withLocality(new LocalityCode($state, 'county', 'Allegheny County'))),
    ));
    expect((string) $assessment->tax->getAmount())->toBe($tax);
})->with([['2025-12-31', '7.00'], ['2026-01-01', '8.00']]);

it('refuses conflicting effective sourcing rows', function (): void {
    $this->rules->rule('us:PA', 'sourcing', ['basis' => 'origin'], '2026-01-01')->install();
    app(SourcingRules::class)->for(new SubdivisionCode('US-PA'), new DateTimeImmutable('2026-09-18'));
})->throws(UnresolvedTaxRule::class, 'Overlapping');

it('refuses unknown or omitted operators instead of inventing OR', function (?string $operator): void {
    $this->rules->rule('us:NY', 'threshold', ['amount' => '500000.00', 'currency' => 'USD', 'binds' => 'remote_seller', 'transactions' => 100, 'combinator' => $operator])->install();
    app(NexusThresholds::class)->for(new SubdivisionCode('US-NY'));
})->with([null, 'xor'])->throws(UnresolvedTaxRule::class, 'combinator');

it('keeps crossing effects separate from the nexus combinator and actor', function (string $crossing): void {
    $this->rules->rule('us:NY', 'threshold', [
        'amount' => '500000.00', 'currency' => 'USD', 'binds' => 'remote_seller',
        'transactions' => 100, 'combinator' => 'sales_and_transactions', 'crossing' => $crossing,
    ], '2019-06-01');
    $this->rules->rule('us:NY', 'threshold', [
        'amount' => '900000.00', 'currency' => 'USD', 'binds' => 'marketplace_facilitator',
        'transactions' => 999, 'combinator' => 'sales_or_transactions', 'crossing' => 'platform_becomes_liable',
    ], '2019-06-01')->install();

    $nexus = app(NexusThresholds::class);
    $state = new SubdivisionCode('US-NY');
    $threshold = $nexus->for($state, new DateTimeImmutable('2019-06-01'));
    expect($threshold->salesDollars)->toBe(500000)
        ->and($threshold->transactions)->toBe(100)
        ->and($threshold->combinator)->toBe(NexusCombinator::SalesAndTransactions)
        // Faithful reading of the published date, including its upstream defect.
        ->and($nexus->for($state, new DateTimeImmutable('2018-06-21')))->toBeNull()
        ->and($nexus->for($state, new DateTimeImmutable('2019-05-31')))->toBeNull();
})->with(['must_register', 'unstated']);

it('does not use crossing to fill in a missing two-limb combinator', function (): void {
    $this->rules->rule('us:NY', 'threshold', [
        'amount' => '500000.00', 'currency' => 'USD', 'binds' => 'remote_seller',
        'transactions' => 100, 'crossing' => 'must_register',
    ])->install();
    app(NexusThresholds::class)->for(new SubdivisionCode('US-NY'));
})->throws(UnresolvedTaxRule::class, 'combinator');

it('refuses a compound remote threshold instead of dropping its conditions from the hint', function (): void {
    $this->rules->rule('us:NY', 'threshold', [
        'binds' => 'remote_seller', 'crossing' => 'must_register',
        'conditions' => [
            ['amount' => '500000.00', 'currency' => 'USD', 'measuredOver' => 'calendar_year', 'counts' => 'gross'],
            ['amount' => '400000.00', 'currency' => 'USD', 'measuredOver' => 'previous_calendar_year', 'counts' => 'gross'],
        ],
        'conditionsCombinator' => 'all_of',
    ])->install();

    app(NexusThresholds::class)->for(new SubdivisionCode('US-NY'));
})->throws(UnresolvedTaxRule::class, 'compound remote-seller threshold');

it('refuses new delivery predicates instead of applying the old boolean answer', function (bool $included, string $field): void {
    // These are illustrative, unagreed extension names. The reader must reject
    // any unknown field, not recognise only one proposed spelling of conditions.
    $this->rules->rule('us:AZ', 'taxable_base', [
        'component' => 'transport_on_taxable_goods', 'included' => $included,
        $field => ['fact' => 'delivery.separatelyStated', 'operator' => 'eq', 'value' => true],
    ])->install();

    app(OrderTaxCalculator::class)->assessOrder(ruleOrder('AZ', [
        new SupplyLine('goods', Money::of('100', 'USD')),
        new SupplyLine('delivery', Money::of('10', 'USD'), isDeliveryCharge: true, delivery: new DeliveryCharge(exclusionConditionsMet: true)),
    ]));
})->with([[true, 'conditions'], [false, 'conditions'], [true, 'when']])
    ->throws(UnresolvedTaxRule::class, 'Unsupported fields');

it('refuses a partial delivery base that cannot be represented by an inclusion boolean', function (): void {
    $this->rules->rule('us:AZ', 'taxable_base', [
        'component' => 'transport_on_taxable_goods', 'included' => true, 'proportion' => '50',
    ])->install();

    app(OrderTaxCalculator::class)->assessOrder(ruleOrder('AZ', [
        new SupplyLine('goods', Money::of('100', 'USD')),
        new SupplyLine('delivery', Money::of('10', 'USD'), isDeliveryCharge: true),
    ]));
})->throws(UnresolvedTaxRule::class, 'unsupported delivery rule');

it('applies a dated rounding policy to sales inclusive prices and credits', function (string $amount, Pricing $pricing, string $tax): void {
    $a = app(TaxCalculator::class)->assess(ruleQuery('IL', $amount, $pricing));
    expect((string) $a->tax->getAmount())->toBe($tax)
        ->and($a->net->plus($a->tax)->isEqualTo($a->gross))->toBeTrue()
        ->and($a->rounding?->scope)->toBe($amount === '0.00' ? null : RoundingScope::Invoice);
})->with([
    ['1.00', Pricing::Exclusive, '0.07'], ['-1.00', Pricing::Exclusive, '-0.07'],
    ['1.00', Pricing::Inclusive, '0.06'], ['-1.00', Pricing::Inclusive, '-0.06'], ['0.00', Pricing::Exclusive, '0.00'],
]);

it('rounds invoice tax once and allocates it independently of input order', function (string $amount, Pricing $pricing, string $firstTax, string $secondTax): void {
    $lines = [new SupplyLine('a', Money::of($amount, 'USD')), new SupplyLine('b', Money::of($amount, 'USD'))];
    foreach ([$lines, array_reverse($lines)] as $ordered) {
        $a = app(OrderTaxCalculator::class)->assessOrder(ruleOrder('IL', $ordered, $pricing));
        expect((string) $a->forLine('a')->tax->getAmount())->toBe($firstTax)
            ->and((string) $a->forLine('b')->tax->getAmount())->toBe($secondTax)
            ->and($a->net()->plus($a->tax())->isEqualTo($a->gross()))->toBeTrue();
        foreach ($a->lines as $line) {
            expect($line->assessment->net->plus($line->assessment->tax)->isEqualTo($line->assessment->gross))->toBeTrue();
        }
    }
})->with([
    ['0.50', Pricing::Exclusive, '0.04', '0.03'], ['-0.50', Pricing::Exclusive, '-0.04', '-0.03'],
    ['0.53', Pricing::Inclusive, '0.04', '0.03'], ['-0.53', Pricing::Inclusive, '-0.04', '-0.03'],
]);

it('allows invoice rounding only where the policy offers a seller election', function (): void {
    $lines = [new SupplyLine('a', Money::of('0.50', 'USD')), new SupplyLine('b', Money::of('0.50', 'USD'))];
    $tax = app(OrderTaxCalculator::class);
    expect((string) $tax->assessOrder(ruleOrder('KS', $lines))->tax()->getAmount())->toBe('0.06')
        ->and((string) $tax->assessOrder(ruleOrder('KS', $lines, scope: RoundingScope::Invoice))->tax()->getAmount())->toBe('0.07');
});

it('includes delivery in invoice rounding and keeps delivery dates', function (): void {
    $a = app(OrderTaxCalculator::class)->assessOrder(ruleOrder('IL', [
        new SupplyLine('a', Money::of('0.50', 'USD')),
        new SupplyLine('delivery', Money::of('0.50', 'USD'), isDeliveryCharge: true),
    ]));
    expect((string) $a->tax()->getAmount())->toBe('0.07')
        ->and($a->forLine('delivery')->taxPoint?->format('Y-m-d'))->toBe('2026-09-18');
});

it('uses the delivery exclusion only when its conditions are confirmed', function (bool $confirmed, string $expected): void {
    $a = app(OrderTaxCalculator::class)->assessOrder(ruleOrder('KS', [
        new SupplyLine('goods', Money::of('100.00', 'USD')),
        new SupplyLine('delivery', Money::of('10.00', 'USD'), isDeliveryCharge: true, delivery: new DeliveryCharge(exclusionConditionsMet: $confirmed)),
    ]));
    $delivery = $a->forLine('delivery');
    expect((string) $delivery->tax->getAmount())->toBe($expected)
        ->and($delivery->treatment)->toBe($confirmed ? TaxTreatment::Exempt : TaxTreatment::Standard)
        ->and($a->net()->plus($a->tax())->isEqualTo($a->gross()))->toBeTrue();
})->with([[true, '0.00'], [false, '0.65']]);

it('refuses a delivery exclusion with unknown conditions', function (): void {
    app(OrderTaxCalculator::class)->assessOrder(ruleOrder('KS', [
        new SupplyLine('goods', Money::of('100.00', 'USD')),
        new SupplyLine('delivery', Money::of('10.00', 'USD'), isDeliveryCharge: true),
    ]));
})->throws(UnresolvedTaxRule::class, 'exclusionConditionsMet');

it('keeps handling separate from transport and does not let a host invent an exclusion', function (): void {
    $a = app(OrderTaxCalculator::class)->assessOrder(ruleOrder('KS', [
        new SupplyLine('goods', Money::of('100.00', 'USD')),
        new SupplyLine('handling', Money::of('10.00', 'USD'), isDeliveryCharge: true, delivery: new DeliveryCharge(DeliveryComponent::Handling, true)),
    ]));
    expect((string) $a->forLine('handling')->tax->getAmount())->toBe('0.65');
});

it('refuses mixed intrastate sourcing that cannot be represented by one place', function (): void {
    $q = ruleQuery('CA');
    app(TaxCalculator::class)->assess(new TaxQuery($q->amount, $q->pricing, $q->place, $q->customer, $q->seller, suppliedAt: $q->suppliedAt, route: new SupplyRoute(shipFrom: $q->place)));
})->throws(UnresolvedTaxRule::class, 'Mixed intrastate sourcing');

it('reconciles mixed rates and their delivery portions in separate invoice groups', function (): void {
    $this->rules->rate('us:IL', '1', 'reduced', 'goods.food.basic')->install();
    $a = app(OrderTaxCalculator::class)->assessOrder(ruleOrder('IL', [
        new SupplyLine('food', Money::of('1.00', 'USD'), TaxClass::Groceries),
        new SupplyLine('goods', Money::of('1.00', 'USD')),
        new SupplyLine('delivery', Money::of('0.20', 'USD'), isDeliveryCharge: true),
    ]));
    // 1.10 at 1% rounds up to .02; 1.10 at 6.25% rounds up to .07.
    expect((string) $a->tax()->getAmount())->toBe('0.09')
        ->and($a->forLine('delivery')->portions)->toHaveCount(2)
        ->and($a->net()->plus($a->tax())->isEqualTo($a->gross()))->toBeTrue();
});

it('reconciles positive and negative lines without rounding twice', function (): void {
    $a = app(OrderTaxCalculator::class)->assessOrder(ruleOrder('IL', [
        new SupplyLine('sale', Money::of('0.50', 'USD')),
        new SupplyLine('credit', Money::of('-0.40', 'USD')),
    ]));
    expect((string) $a->tax()->getAmount())->toBe('0.01')
        ->and((string) $a->gross()->getAmount())->toBe('0.11');
});

it('preserves each line pricing mode when invoice rounding allocates a cent', function (): void {
    $a = app(OrderTaxCalculator::class)->assessOrder(ruleOrder('IL', [
        new SupplyLine('a', Money::of('0.50', 'USD')),
        new SupplyLine('b', Money::of('0.53', 'USD'), pricing: Pricing::Inclusive),
    ]));
    expect((string) $a->tax()->getAmount())->toBe('0.07')
        ->and((string) $a->forLine('a')->net->getAmount())->toBe('0.50')
        ->and((string) $a->forLine('b')->gross->getAmount())->toBe('0.53')
        ->and($a->net()->plus($a->tax())->isEqualTo($a->gross()))->toBeTrue();
});

it('uses published decimal places even when money has more precision', function (): void {
    $q = ruleQuery('IL');
    $a = app(TaxCalculator::class)->assess(new TaxQuery(Money::of('1.0000', 'USD', new CustomContext(4)), $q->pricing, $q->place, $q->customer, $q->seller, suppliedAt: $q->suppliedAt));
    expect((string) $a->tax->getAmount())->toBe('0.0700');
});

it('refuses a historical gap in the rounding policy', function (): void {
    app(TaxCalculator::class)->assess(ruleQuery('IL', date: '2000-07-06'));
})->throws(UnresolvedTaxRule::class, 'No rounding policy covers');

it('refuses unknown rounding methods', function (): void {
    $this->rules->rule('us:AZ', 'rounding', ['method' => 'future_method', 'places' => 2, 'appliesTo' => 'invoice', 'aggregatesLocal' => true])->install();
    app(TaxCalculator::class)->assess(ruleQuery('AZ'));
})->throws(UnresolvedTaxRule::class, 'Unsupported rounding method');

it('does not extend a delivery rule into an uncovered historical period', function (): void {
    $q = ruleQuery('KS', date: '2026-09-13');
    app(TaxCalculator::class)->assess(new TaxQuery($q->amount, $q->pricing, $q->place, $q->customer, $q->seller, suppliedAt: $q->suppliedAt, delivery: new DeliveryCharge(exclusionConditionsMet: true, goodsTaxable: true)));
})->throws(UnresolvedTaxRule::class, 'No applicable delivery rule');

it('does not require local aggregation metadata for a single state share', function (): void {
    $this->rules->rule('us:AZ', 'rounding', ['method' => 'half_up', 'places' => 2, 'appliesTo' => 'seller_election'])->install();
    expect((string) app(TaxCalculator::class)->assess(ruleQuery('AZ'))->tax->getAmount())->toBe('0.06');
});

it('allocates invoice-rounded combined tax back to all taxing authorities', function (): void {
    $this->rules->rate('us:IL:CITY-1', '1', 'local_component')->install();
    app()->instance(LocalAuthorityResolver::class, new class implements LocalAuthorityResolver
    {
        public function authoritiesFor(Jurisdiction $jurisdiction, ?DateTimeImmutable $at = null): ?array
        {
            return ['us:IL', 'us:IL:CITY-1'];
        }
    });
    $a = app(OrderTaxCalculator::class)->assessOrder(ruleOrder('IL', [
        new SupplyLine('a', Money::of('0.20', 'USD')),
        new SupplyLine('b', Money::of('0.20', 'USD')),
    ]));
    expect((string) $a->tax()->getAmount())->toBe('0.03');
    foreach ($a->lines as $line) {
        expect($line->assessment->breakdown?->total()?->isEqualTo($line->assessment->tax))->toBeTrue();
    }
    $total = Money::zero('USD');
    foreach ($a->taxByAuthority() as $authority) {
        $total = $total->plus($authority->tax);
    }
    expect($total->isEqualTo($a->tax()))->toBeTrue();
});

it('requires neither delivery conditions nor tax on a free delivery', function (): void {
    $a = app(OrderTaxCalculator::class)->assessOrder(ruleOrder('KS', [
        new SupplyLine('goods', Money::of('100.00', 'USD')),
        new SupplyLine('delivery', Money::of('0.00', 'USD'), isDeliveryCharge: true),
    ]));
    expect((string) $a->forLine('delivery')->tax->getAmount())->toBe('0.00');
});

it('keeps an exempt mixed-cart delivery exempt across all its portions', function (): void {
    $this->rules->rate('us:KS', '0', 'exempt', 'goods.medicine.prescription');
    $this->rules->rule('us:KS', 'taxable_base', ['component' => 'transport_on_exempt_goods', 'included' => false])->install();
    $a = app(OrderTaxCalculator::class)->assessOrder(ruleOrder('KS', [
        new SupplyLine('goods', Money::of('100.00', 'USD')),
        new SupplyLine('medicine', Money::of('100.00', 'USD'), TaxClass::PrescriptionMedicine),
        new SupplyLine('delivery', Money::of('10.00', 'USD'), isDeliveryCharge: true, delivery: new DeliveryCharge(exclusionConditionsMet: true)),
    ]));
    expect((string) $a->forLine('delivery')->tax->getAmount())->toBe('0.00')
        ->and($a->forLine('delivery')->isExempt())->toBeTrue();
});

it('preserves a partial taxable base when zero line taxes acquire an invoice cent', function (): void {
    // Invented threshold: tests invoice reconciliation, not a Kansas clothing exemption.
    $this->rules->rule('us:KS', 'price_exemption', ['category' => 'goods.clothing', 'capAmount' => '100.00', 'capCurrency' => 'USD', 'above' => 'excess_taxable'])->install();
    app()->instance(LocalAuthorityResolver::class, new class implements LocalAuthorityResolver
    {
        public function authoritiesFor(Jurisdiction $jurisdiction, ?DateTimeImmutable $at = null): ?array
        {
            return ['us:KS'];
        }
    });
    $a = app(OrderTaxCalculator::class)->assessOrder(ruleOrder('KS', [
        new SupplyLine('a', Money::of('100.05', 'USD'), TaxClass::Clothing),
        new SupplyLine('b', Money::of('100.05', 'USD'), TaxClass::Clothing),
    ], scope: RoundingScope::Invoice));
    expect((string) $a->tax()->getAmount())->toBe('0.01')
        ->and((string) $a->forLine('a')->taxableBase->getAmount())->toBe('0.05')
        ->and((string) $a->forLine('b')->taxableBase->getAmount())->toBe('0.05')
        ->and((string) $a->forLine('a')->breakdown?->lines[0]->taxableAmount->getAmount())->toBe('0.05')
        ->and($a->forLine('a')->breakdown?->total()?->isEqualTo($a->forLine('a')->tax))->toBeTrue();
});

it('refuses to invent a delivery allocation for partially exempt goods', function (): void {
    $this->rules->rule('us:KS', 'price_exemption', ['category' => 'goods.clothing', 'capAmount' => '100.00', 'capCurrency' => 'USD', 'above' => 'excess_taxable'])->install();
    app(OrderTaxCalculator::class)->assessOrder(ruleOrder('KS', [
        new SupplyLine('goods', Money::of('125.00', 'USD'), TaxClass::Clothing),
        new SupplyLine('delivery', Money::of('10.00', 'USD'), isDeliveryCharge: true, delivery: new DeliveryCharge(exclusionConditionsMet: false)),
    ]));
})->throws(UnresolvedTaxRule::class, 'partially exempt goods');

it('can exclude delivery of partially exempt goods when no taxable allocation is needed', function (): void {
    $this->rules->rule('us:KS', 'price_exemption', ['category' => 'goods.clothing', 'capAmount' => '100.00', 'capCurrency' => 'USD', 'above' => 'excess_taxable'])->install();
    $a = app(OrderTaxCalculator::class)->assessOrder(ruleOrder('KS', [
        new SupplyLine('goods', Money::of('125.00', 'USD'), TaxClass::Clothing),
        new SupplyLine('delivery', Money::of('10.00', 'USD'), isDeliveryCharge: true, delivery: new DeliveryCharge(exclusionConditionsMet: true)),
    ]));
    expect($a->forLine('delivery')->isExempt())->toBeTrue()
        ->and((string) $a->forLine('delivery')->tax->getAmount())->toBe('0.00');
});
