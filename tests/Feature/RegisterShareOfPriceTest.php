<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\LocalityScheme;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RoundingScope;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Regime\UsSalesTaxRegime;
use Cbox\Tax\Register\Reader\RegisterCompatibility;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Support\Facades\Http;

/*
 * A state that taxes only a share of the price (register schema 2.8).
 *
 * Texas taxes data processing, SaaS and hosting at its own 6.25% on 80% of the price,
 * and says its local taxes are computed on the same 80% (34 TAC §3.330(c)(1),(4)).
 * Arizona taxes prime contracting on 65% of the gross and says nothing about its
 * cities. The register publishes each state's own rate as it is, and a `taxable_base`
 * rule on the `supplied_item` beside it.
 */

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/share-of-price');
    Http::preventStrayRequests();

    $this->register = FakeRegister::at(config('tax.register.store'))
        ->category('services')
        ->category('services.digital', 'services')->category('services.digital.saas', 'services.digital')
        ->category('services.construction', 'services')
        ->rate('us:TX', '6.25', from: '1990-01-01')
        ->rate('us:TX:CITY-AUSTIN', '1', 'local_component', from: '1990-01-01')->named('us:TX:CITY-AUSTIN', 'Austin')
        ->rate('us:TX:DISTRICT-CAPMETRO', '1', 'local_component', from: '1990-01-01')->named('us:TX:DISTRICT-CAPMETRO', 'Capital Metro')
        ->boundary('TX', '78701', ['state:TX', 'city:AUSTIN', 'district:CAPMETRO'])
        ->rate('us:AZ', '5.6', from: '1990-01-01')
        ->rule('us:TX', 'taxable_base', [
            'component' => 'supplied_item', 'included' => true, 'category' => 'services.digital.saas',
            'proportion' => '80', 'localTaxes' => 'same_share',
        ], '2026-01-01')
        ->rule('us:AZ', 'taxable_base', [
            'component' => 'supplied_item', 'included' => true, 'category' => 'services.construction',
            'proportion' => '65', 'localTaxes' => 'unstated',
        ], '2026-01-01');
    $this->register->install();
});

function shareQuery(string $state, string $amount, string $key, ?string $zip = null, Pricing $pricing = Pricing::Exclusive, bool $elected = false): TaxQuery
{
    $country = new CountryCode('US');
    $subdivision = new SubdivisionCode('US-'.$state);
    $place = app(JurisdictionRepository::class)->find($country, $subdivision);

    return new TaxQuery(
        amount: Money::of($amount, 'USD'),
        pricing: $pricing,
        place: $zip === null ? $place : $place->withLocality(new LocalityCode($subdivision, LocalityScheme::Zip9->value, $zip)),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations($country, [new SellerRegistration($country, $subdivision, scheme: $elected ? UsSalesTaxRegime::REMOTE_ELECTION_SCHEME : null)]),
        suppliedAt: new DateTimeImmutable('2026-09-22'),
        categoryKey: $key,
    );
}

it('taxes Texas SaaS in Austin on 80% of the price, state and local alike', function (): void {
    $a = app(TaxCalculator::class)->assess(shareQuery('TX', '100.00', 'services.digital.saas', '78701-0001'));
    $lines = $a->breakdown->lines ?? [];
    $state = collect($lines)->firstWhere('code', 'us:TX');
    $locals = collect($lines)->reject(fn ($line): bool => $line->code === 'us:TX');

    // 8.25% on $80.00. The rate is the statutory stack; the share travels beside it.
    expect((string) $a->rate?->percentage)->toBe('8.25')
        ->and((string) $a->rate?->taxableShare)->toBe('80')
        ->and((string) $a->tax->getAmount())->toBe('6.60')
        ->and((string) $a->gross->getAmount())->toBe('106.60')
        ->and((string) $state?->tax->getAmount())->toBe('5.00')
        ->and((string) $locals->reduce(fn (?Money $sum, $line) => $sum === null ? $line->tax : $sum->plus($line->tax))?->getAmount())->toBe('1.60')
        ->and((string) $state?->taxableAmount->getAmount())->toBe('80.00')
        ->and($a->reason)->toContain('on 80% of the price');
});

it('reads the share back out of a tax-inclusive Texas price', function (): void {
    $a = app(TaxCalculator::class)->assess(shareQuery('TX', '106.60', 'services.digital.saas', '78701-0001', Pricing::Inclusive));

    expect((string) $a->net->getAmount())->toBe('100.00')
        ->and((string) $a->tax->getAmount())->toBe('6.60');
});

it('rounds a Texas SaaS document once on the share, not on the whole price', function (): void {
    // Three $1.00 seats: 6.6 cents each, 19.8 cents together. Line by line that is 21
    // cents; rounded once it is 20; on the whole price it would have been 25.
    $this->register->rule('us:TX', 'rounding', ['method' => 'half_up', 'places' => 2, 'appliesTo' => 'invoice', 'aggregatesLocal' => true])->install();
    $query = shareQuery('TX', '1.00', 'services.digital.saas', '78701-0001');
    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder($query->place, $query->customer, $query->seller, Pricing::Exclusive, [
        new SupplyLine('seat-a', Money::of('1.00', 'USD'), categoryKey: 'services.digital.saas'),
        new SupplyLine('seat-b', Money::of('1.00', 'USD'), categoryKey: 'services.digital.saas'),
        new SupplyLine('seat-c', Money::of('1.00', 'USD'), categoryKey: 'services.digital.saas'),
    ], suppliedAt: $query->suppliedAt, roundingScope: RoundingScope::Invoice));

    $authorities = $order->taxByAuthority() ?? [];
    $sum = array_reduce($authorities, static fn (?Money $carry, $t) => $carry === null ? $t->tax : $carry->plus($t->tax));

    expect((string) $order->tax()->getAmount())->toBe('0.20')
        ->and((string) $sum?->getAmount())->toBe('0.20')
        ->and((string) $order->forLine('seat-a')->breakdown?->lines[0]->taxableAmount->getAmount())->toBe('0.80');
});

it('leaves a Texas supply outside the rule on the whole price', function (): void {
    $a = app(TaxCalculator::class)->assess(shareQuery('TX', '100.00', 'services', '78701-0001'));

    expect($a->rate?->taxableShare)->toBeNull()
        ->and((string) $a->tax->getAmount())->toBe('8.25');
});

it('reaches a rule filed at a parent from a key beneath it, nearest rung first', function (): void {
    $this->register->category('services.digital.saas.crm', 'services.digital.saas')->install();
    $a = app(TaxCalculator::class)->assess(shareQuery('TX', '100.00', 'services.digital.saas.crm', '78701-0001'));

    expect((string) $a->tax->getAmount())->toBe('6.60');
});

it('refuses carrying a share onto a delivery charge no rule describes', function (): void {
    $query = shareQuery('TX', '100.00', 'services.digital.saas', '78701-0001');
    $this->register->rule('us:TX', 'taxable_base', ['component' => 'transport_on_taxable_goods', 'included' => true])->install();

    app(OrderTaxCalculator::class)->assessOrder(new TaxOrder($query->place, $query->customer, $query->seller, Pricing::Exclusive, [
        new SupplyLine('licence', Money::of('100.00', 'USD'), categoryKey: 'services.digital.saas'),
        new SupplyLine('shipping', Money::of('10.00', 'USD'), isDeliveryCharge: true),
    ], suppliedAt: $query->suppliedAt));
})->throws(UnresolvedTaxRule::class, 'partially exempt');

it('keeps the share under the Texas single local rate a remote seller elected', function (): void {
    // 6.25% state plus the 1.75% single local rate, both on 80%: $6.40 on $100.
    $this->register->rule('us:TX', 'remote_seller_election', [
        'program' => 'Single Local Use Tax Rate', 'mechanic' => 'single_local_rate',
        'ratePercent' => '1.75', 'statute' => 'Tex. Tax Code § 151.0595',
    ], '2026-01-01', '2026-12-31')->install();

    $a = app(TaxCalculator::class)->assess(shareQuery('TX', '100.00', 'services.digital.saas', elected: true));

    expect((string) $a->rate?->percentage)->toBe('8')
        ->and((string) $a->tax->getAmount())->toBe('6.40')
        ->and($a->reason)->toContain('on 80% of the price');
});

it('refuses an elected figure where the share does not say what its local taxes do', function (): void {
    $this->register->rule('us:AZ', 'remote_seller_election', [
        'program' => 'Illustrative flat rate', 'mechanic' => 'flat_total', 'ratePercent' => '8', 'statute' => 'none',
    ])->install();

    app(TaxCalculator::class)->assess(shareQuery('AZ', '100.00', 'services.construction', elected: true));
})->throws(UnresolvedTaxRule::class, 'includes a local rate');

it('taxes Arizona prime contracting on 65% where the state tax is the whole stack', function (): void {
    $a = app(TaxCalculator::class)->assess(shareQuery('AZ', '100000.00', 'services.construction'));

    expect((string) $a->rate?->percentage)->toBe('5.6')
        ->and((string) $a->tax->getAmount())->toBe('3640.00');
});

it('refuses Arizona prime contracting once a local tax is in the stack', function (): void {
    // The register does not say what base Phoenix taxes contracting on. Taxed on the
    // whole price it may over-collect, on 65% it may under-collect: neither is given.
    $this->register
        ->rate('us:AZ:CITY-PHOENIX', '2.3', 'local_component', from: '1990-01-01')
        ->boundary('AZ', '85004', ['state:AZ', 'city:PHOENIX'])
        ->install();

    app(TaxCalculator::class)->assess(shareQuery('AZ', '100000.00', 'services.construction', '85004-0001'));
})->throws(UnresolvedTaxRule::class, 'does not say whether its local taxes');

it('does not apply a share rule that says nothing about local taxes, as before schema 2.8', function (): void {
    // Georgia, Kansas, Minnesota and Utah publish bundled software maintenance this way.
    $this->register
        ->rate('us:GA', '4', from: '1990-01-01')
        ->rule('us:GA', 'taxable_base', [
            'component' => 'supplied_item', 'included' => true, 'category' => 'services.digital.saas', 'proportion' => '50',
        ])
        ->install();

    $a = app(TaxCalculator::class)->assess(shareQuery('GA', '100.00', 'services.digital.saas'));

    expect($a->rate?->taxableShare)->toBeNull()
        ->and((string) $a->tax->getAmount())->toBe('4.00');
});

it('lets a nearer rule without localTaxes govern rather than reach past it', function (): void {
    $this->register->rule('us:TX', 'taxable_base', [
        'component' => 'supplied_item', 'included' => true, 'category' => 'services.digital.saas', 'proportion' => '50',
    ])->install();

    // Two rules on one rung are two answers.
    app(TaxCalculator::class)->assess(shareQuery('TX', '100.00', 'services.digital.saas', '78701-0001'));
})->throws(UnresolvedTaxRule::class, 'Overlapping');

it('refuses a share rule it cannot read as a share', function (array $payload, string $message): void {
    $this->register->category('services.construction.residential', 'services.construction')->rule('us:AZ', 'taxable_base', [
        'component' => 'supplied_item', 'included' => true, 'category' => 'services.construction.residential', 'localTaxes' => 'same_share', ...$payload,
    ])->install();

    expect(fn () => app(TaxCalculator::class)->assess(shareQuery('AZ', '100.00', 'services.construction.residential')))
        ->toThrow(UnresolvedTaxRule::class, $message);
})->with([
    'no proportion' => [[], 'publishes no proportion'],
    'above the price' => [['proportion' => '120'], 'not a share of the price'],
    'excluded' => [['proportion' => '50', 'included' => false], 'not an unconditional inclusion'],
    'decided' => [['proportion' => '50', 'decision' => ['when' => []]], 'not an unconditional inclusion'],
    'unknown local base' => [['proportion' => '50', 'localTaxes' => 'proportional'], 'which this reader does not know'],
]);

it('reads schema 2.8 and still refuses 2.9', function (): void {
    expect(RegisterCompatibility::reads('2.8.0'))->toBeTrue()
        ->and(RegisterCompatibility::reads('2.8.3'))->toBeTrue()
        ->and(RegisterCompatibility::reads('2.9.0'))->toBeFalse();
});

it('still refuses an unknown field on a taxable_base rule', function (): void {
    RegisterCompatibility::applicable([
        'jurisdiction' => 'us:TX', 'kind' => 'taxable_base',
        'payload' => ['component' => 'supplied_item', 'included' => true, 'category' => 'services.digital.saas', 'proportion' => '80', 'localTaxes' => 'same_share', 'localShare' => '80'],
    ]);
})->throws(UnresolvedTaxRule::class, 'payload.localShare');

it('never lets a share rule reach the delivery reader', function (): void {
    $query = shareQuery('AZ', '100.00', 'services.construction');
    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder($query->place, $query->customer, $query->seller, Pricing::Exclusive, [
        new SupplyLine('goods', Money::of('100.00', 'USD')),
        new SupplyLine('freight', Money::of('10.00', 'USD'), isDeliveryCharge: true),
    ], suppliedAt: $query->suppliedAt));

    // No delivery rule is published for Arizona, so the freight refuses for the reason
    // it always did — not over the share rule beside it.
})->throws(UnresolvedTaxRule::class, 'No applicable delivery rule');
