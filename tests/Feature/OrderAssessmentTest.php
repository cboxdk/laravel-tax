<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\InvalidTaxOrder;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\ValueObjects\BreakdownLine;
use Cbox\Tax\ValueObjects\LineAssessment;
use Cbox\Tax\ValueObjects\OrderAssessment;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxBreakdown;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(OrderTaxCalculator::class);
});

function euOrder(array $lines, Pricing $pricing = Pricing::Exclusive): TaxOrder
{
    return new TaxOrder(
        place: test()->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
        pricing: $pricing,
        lines: $lines,
    );
}

it('assesses every line of a document and ties each answer to its line', function () {
    // The invoice a Laravel SaaS actually issues: a subscription, metered usage,
    // and one-off onboarding services.
    $order = euOrder([
        new SupplyLine('sub', Money::of('100.00', 'DKK'), TaxClass::DigitalService),
        new SupplyLine('usage', Money::of('37.50', 'DKK'), TaxClass::DigitalService),
        new SupplyLine('onboarding', Money::of('2500.00', 'DKK'), TaxClass::ProfessionalService),
    ]);

    $assessment = $this->tax->assessOrder($order);

    expect($assessment->lines)->toHaveCount(3)
        ->and((string) $assessment->forLine('usage')?->tax->getAmount())->toBe('9.38')  // 25% of 37.50
        ->and($assessment->forLine('nope'))->toBeNull();
});

it('sums the document from the rounded lines, never from the totals', function () {
    // Three lines at 25% DKK. Per line: 3.33 + 3.33 + 3.33 = 9.99.
    // Rate applied to the summed net instead: 40.00 × 25% = 10.00.
    // The second number does not equal the invoice rows beneath it.
    $order = euOrder([
        new SupplyLine('a', Money::of('13.33', 'DKK')),
        new SupplyLine('b', Money::of('13.33', 'DKK')),
        new SupplyLine('c', Money::of('13.34', 'DKK')),
    ]);

    $assessment = $this->tax->assessOrder($order);

    expect((string) $assessment->net()->getAmount())->toBe('40.00')
        ->and((string) $assessment->tax()->getAmount())->toBe('10.00')
        ->and((string) $assessment->gross()->getAmount())->toBe('50.00');

    // ...and whatever the total is, it is exactly the sum of the line figures.
    $summed = array_reduce(
        $assessment->assessments(),
        static fn (?Money $carry, $a): Money => $carry === null ? $a->tax : $carry->plus($a->tax),
    );

    expect((string) $summed?->getAmount())->toBe((string) $assessment->tax()->getAmount());
});

it('lets a line override the document pricing', function () {
    // A subscription quoted VAT-inclusive beside usage quoted exclusive is an
    // ordinary invoice; one document-level setting cannot express it.
    $order = euOrder([
        new SupplyLine('inclusive', Money::of('125.00', 'DKK'), pricing: Pricing::Inclusive),
        new SupplyLine('exclusive', Money::of('100.00', 'DKK')),
    ]);

    $assessment = $this->tax->assessOrder($order);

    expect((string) $assessment->forLine('inclusive')?->net->getAmount())->toBe('100.00')
        ->and((string) $assessment->forLine('inclusive')?->gross->getAmount())->toBe('125.00')
        ->and((string) $assessment->forLine('exclusive')?->net->getAmount())->toBe('100.00')
        ->and((string) $assessment->forLine('exclusive')?->gross->getAmount())->toBe('125.00');
});

it('lets a line carry its own exemption', function () {
    $order = new TaxOrder(
        place: $this->geo->find(new CountryCode('DK')),
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode('DK')),
        pricing: Pricing::Exclusive,
        lines: [
            new SupplyLine('taxed', Money::of('100.00', 'DKK')),
            new SupplyLine('exempt', Money::of('100.00', 'DKK'), exemption: $this->taxExemption(countries: ['DK'])),
        ],
    );

    $assessment = $this->tax->assessOrder($order);

    expect($assessment->forLine('taxed')?->treatment)->toBe(TaxTreatment::Standard)
        ->and($assessment->forLine('exempt')?->treatment)->toBe(TaxTreatment::Exempt)
        ->and((string) $assessment->tax()->getAmount())->toBe('25.00');
});

// ---- A document is one currency ------------------------------------------

it('refuses a document with no lines', function () {
    expect(fn () => euOrder([]))->toThrow(InvalidTaxOrder::class, 'at least one line');
});

it('refuses a document that mixes currencies', function () {
    // Money would refuse this three layers down with a message about currency
    // codes; naming the invoice here is the difference between a bug report and
    // a fix.
    expect(fn () => euOrder([
        new SupplyLine('dkk', Money::of('100.00', 'DKK')),
        new SupplyLine('eur', Money::of('100.00', 'EUR')),
    ]))->toThrow(InvalidTaxOrder::class, 'eur');
});

// ---- Per-authority roll-up for remittance ---------------------------------

it('rolls the document tax up per taxing authority', function () {
    $seller = new SellerRegistrations(new CountryCode('US'), [
        new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-KS')),
    ]);

    $kansasCity = $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-KS'))
        ->withLocality(new LocalityCode(
            new SubdivisionCode('US-KS'),
            UsTaxDatasetRateSource::ZIP9_SCHEME,
            '66101-6200',
        ));

    $order = new TaxOrder(
        place: $kansasCity,
        customer: CustomerType::Consumer,
        seller: $seller,
        pricing: Pricing::Exclusive,
        lines: [
            new SupplyLine('a', Money::of('100.00', 'USD')),
            new SupplyLine('b', Money::of('200.00', 'USD')),
        ],
    );

    $assessment = $this->tax->assessOrder($order);
    $authorities = $assessment->taxByAuthority();

    expect($authorities)->toHaveCount(3);

    // Whatever the split, it must add back up to the document's tax — that is the
    // property a per-jurisdiction remittance depends on.
    $total = array_reduce(
        $authorities ?? [],
        static fn (?Money $carry, $a): Money => $carry === null ? $a->tax : $carry->plus($a->tax),
    );

    expect((string) $total?->getAmount())->toBe((string) $assessment->tax()->getAmount())
        ->and(array_map(fn ($a): string => $a->level->value, $authorities ?? []))
        ->toBe(['state', 'county', 'city']);
});

it('refuses a partial roll-up rather than quietly omitting a line', function () {
    // One rooftop line (decomposable) and one bare-state line (not). A roll-up of
    // just the first would look like the document's split while silently dropping
    // the second — and look entirely reasonable doing it.
    $seller = new SellerRegistrations(new CountryCode('US'), [
        new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-KS')),
    ]);

    $plain = $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-KS'));
    $rooftop = $plain->withLocality(new LocalityCode(
        new SubdivisionCode('US-KS'),
        UsTaxDatasetRateSource::ZIP9_SCHEME,
        '66101-6200',
    ));

    $withRooftop = $this->tax->assessOrder(new TaxOrder(
        place: $rooftop, customer: CustomerType::Consumer, seller: $seller,
        pricing: Pricing::Exclusive, lines: [new SupplyLine('a', Money::of('100.00', 'USD'))],
    ));

    $withoutRooftop = $this->tax->assessOrder(new TaxOrder(
        place: $plain, customer: CustomerType::Consumer, seller: $seller,
        pricing: Pricing::Exclusive, lines: [new SupplyLine('a', Money::of('100.00', 'USD'))],
    ));

    expect($withRooftop->taxByAuthority())->not->toBeNull()
        ->and($withoutRooftop->taxByAuthority())->toBeNull();
});

it('ignores untaxed lines when rolling up, rather than refusing over them', function () {
    // A reverse-charged or exempt line has no breakdown and nothing to attribute.
    // It must not make the whole roll-up unavailable.
    $order = new TaxOrder(
        place: $this->geo->find(new CountryCode('DK')),
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode('DK')),
        pricing: Pricing::Exclusive,
        lines: [new SupplyLine('exempt', Money::of('100.00', 'DKK'), exemption: $this->taxExemption(countries: ['DK']))],
    );

    expect($this->tax->assessOrder($order)->taxByAuthority())->toBe([]);
});

// ---- A document is a set of supplies, so the return already accepts it ----

it('feeds the return aggregator without the aggregator changing', function () {
    $order = euOrder([
        new SupplyLine('a', Money::of('100.00', 'DKK')),
        new SupplyLine('b', Money::of('300.00', 'DKK')),
    ]);

    $return = $this->app->make(ReturnAggregator::class)
        ->aggregate($this->tax->assessOrder($order)->assessments());

    $line = $return->lineFor(new CountryCode('DK'), 'DKK');

    expect((string) $line?->net->getAmount())->toBe('400.00')
        ->and((string) $line?->tax->getAmount())->toBe('100.00')
        ->and($line?->count)->toBe(2);
});

it('resolves the shipped calculator directly for both contracts', function () {
    expect($this->app->make(OrderTaxCalculator::class))
        ->toBe($this->app->make(TaxCalculator::class));
});

it('gives document support to a host that bound its own calculator', function () {
    // Rebinding TaxCalculator is a supported thing to do. It must not silently
    // hand documents to the SHIPPED calculator, which would bypass the host's own
    // tax logic for every multi-line invoice while single supplies still used it.
    $host = new class implements TaxCalculator
    {
        public function assess(TaxQuery $query): TaxAssessment
        {
            return new TaxAssessment(
                treatment: TaxTreatment::Standard,
                net: $query->amount,
                tax: Money::of('1.00', 'DKK'),
                gross: $query->amount->plus(Money::of('1.00', 'DKK')),
                placeOfSupply: $query->place,
                rate: null,
                reason: 'the host decided',
            );
        }
    };

    $this->app->forgetInstance(OrderTaxCalculator::class);
    $this->app->instance(TaxCalculator::class, $host);

    $assessment = $this->app->make(OrderTaxCalculator::class)->assessOrder(euOrder([
        new SupplyLine('a', Money::of('100.00', 'DKK')),
        new SupplyLine('b', Money::of('100.00', 'DKK')),
    ]));

    expect((string) $assessment->tax()->getAmount())->toBe('2.00')
        ->and($assessment->forLine('a')?->reason)->toBe('the host decided');
});

// ---- Line ids are how tax gets back onto the invoice ----------------------

it('refuses duplicate line ids rather than losing one line', function () {
    expect(fn () => euOrder([
        new SupplyLine('shipping', Money::of('10.00', 'DKK')),
        new SupplyLine('shipping', Money::of('20.00', 'DKK')),
    ]))->toThrow(InvalidTaxOrder::class, 'share the id');
});

it('refuses an unidentified line', function () {
    expect(fn () => euOrder([new SupplyLine('', Money::of('10.00', 'DKK'))]))
        ->toThrow(InvalidTaxOrder::class, 'non-empty id');
});

it('refuses a roll-up when a taxed line reports an empty breakdown', function () {
    // An empty breakdown is the ABSENCE of a split, not a split into nothing.
    // Merging it as a zero contribution would drop that line's tax from the
    // roll-up while the remaining figures still looked plausible.
    $assessment = new OrderAssessment([
        new LineAssessment('a', new TaxAssessment(
            treatment: TaxTreatment::Standard,
            net: Money::of('100.00', 'DKK'),
            tax: Money::of('25.00', 'DKK'),
            gross: Money::of('125.00', 'DKK'),
            placeOfSupply: $this->geo->find(new CountryCode('DK')),
            rate: null,
            reason: 'taxed but undecomposed',
            breakdown: new TaxBreakdown,
        )),
    ]);

    expect($assessment->taxByAuthority())->toBeNull();
});

it('keeps two unidentified authorities apart instead of summing them', function () {
    // Two special districts both reporting a null code are two districts. Keyed
    // on level alone they would merge, and the roll-up would report one district
    // owed both shares.
    $place = $this->geo->find(new CountryCode('DK'));

    $line = fn (string $id): LineAssessment => new LineAssessment($id, new TaxAssessment(
        treatment: TaxTreatment::Standard,
        net: Money::of('100.00', 'DKK'),
        tax: Money::of('2.00', 'DKK'),
        gross: Money::of('102.00', 'DKK'),
        placeOfSupply: $place,
        rate: null,
        reason: 'two unnamed districts',
        breakdown: new TaxBreakdown([
            new BreakdownLine(JurisdictionLevel::SpecialDistrict, BigDecimal::of('1'), Money::of('100.00', 'DKK'), Money::of('1.00', 'DKK')),
            new BreakdownLine(JurisdictionLevel::SpecialDistrict, BigDecimal::of('1'), Money::of('100.00', 'DKK'), Money::of('1.00', 'DKK')),
        ]),
    ));

    $authorities = new OrderAssessment([$line('a')])->taxByAuthority();

    expect($authorities)->toHaveCount(2)
        ->and(array_map(fn ($a): string => (string) $a->tax->getAmount(), $authorities ?? []))
        ->toBe(['1.00', '1.00']);
});

it('reaches an outcome no single supply could not', function () {
    // The order plane adds no tax logic: a one-line document must equal the single
    // supply it wraps, gate for gate.
    $single = $this->app->make(TaxCalculator::class)->assess(
        euOrder([new SupplyLine('a', Money::of('100.00', 'DKK'))])->queryFor(
            new SupplyLine('a', Money::of('100.00', 'DKK'))
        )
    );

    $document = $this->tax->assessOrder(euOrder([new SupplyLine('a', Money::of('100.00', 'DKK'))]));

    expect((string) $document->tax()->getAmount())->toBe((string) $single->tax->getAmount())
        ->and($document->forLine('a')?->treatment)->toBe($single->treatment);
});
