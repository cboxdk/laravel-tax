<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRegister;
use Cbox\Tax\Contracts\UnstatedReadings;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\LocalityScheme;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Regime\UsSalesTaxRegime;
use Cbox\Tax\Register\Compile\Compiler;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\DecisionFacts;
use Cbox\Tax\ValueObjects\RateRecord;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxBreakdown;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\UnsettledCondition;
use Illuminate\Support\Facades\Http;

/*
 * Register schema 2.8, read whole, from a release compiled the way `tax:data:sync`
 * compiles one.
 *
 * 2.8 adds four things a reader must understand before it activates a release: the
 * share-of-price base (read since 0.21.0), the separate levies — `admissions`,
 * `communications`, `lodging`, beside `excise` — which are never a category's answer
 * and always a tax added beside it, the `read_unstated` record, which is never an
 * answer at all, and two obligation shapes for remote sellers (Hawaii's first return
 * after the following filing period, Connecticut's fixed measuring window).
 */

const SCHEMA_28_RELEASE = '2026.11.14-280';

/** @return array<string, mixed> */
function schema28Rate(string $code, string $percentage, string $kind = 'standard', ?string $category = null, string $taxType = 'sales_use', array $extra = []): array
{
    return [
        'jurisdiction' => $code, 'taxType' => $taxType, 'borneBy' => 'customer', 'mayBePassedOn' => false,
        'kind' => $kind, 'basis' => 'ad_valorem', 'percentage' => $percentage,
        'effective' => ['from' => '2000-01-01', 'until' => null], 'classification' => null, 'category' => $category,
        'provenance' => ['source' => 'fixture', 'snapshot' => str_repeat('0', 64), 'capturedAt' => '2026-11-14T00:00:00+00:00', 'note' => null],
        ...$extra,
    ];
}

/** @return array<string, mixed> */
function schema28Rule(string $code, string $kind, array $payload, array $provenance = []): array
{
    return [
        'jurisdiction' => $code, 'kind' => $kind,
        'effective' => ['from' => '2000-01-01', 'until' => null],
        'payload' => $payload,
        'provenance' => ['source' => 'fixture', 'snapshot' => str_repeat('0', 64), 'capturedAt' => '2026-11-14T00:00:00+00:00', 'note' => null, ...$provenance],
    ];
}

/**
 * A 2.8.0 release with one of everything 2.8 adds.
 *
 * @param  list<array<string, mixed>>  $extraRules
 */
function publishSchema28Register(array $extraRules = []): void
{
    $base = 'https://data.cboxtax.com/api/v1/releases/'.SCHEMA_28_RELEASE;
    $states = ['AZ' => 'Arizona', 'CT' => 'Connecticut', 'HI' => 'Hawaii', 'SC' => 'South Carolina', 'TX' => 'Texas', 'VA' => 'Virginia'];
    $categories = [
        ['services', null], ['services.cultural_admission', 'services'], ['services.telecom', 'services'],
        ['services.telecom.coin_operated', 'services.telecom'], ['services.accommodation', 'services'],
        ['services.digital', 'services'], ['services.digital.saas', 'services.digital'],
    ];

    $rates = [
        // South Carolina exempts an admission from its sales tax and levies 5% on it
        // under the admissions tax (S.C. Code § 12-21-2420).
        schema28Rate('us:SC', '6'),
        schema28Rate('us:SC', '0', 'exempt', 'services.cultural_admission'),
        schema28Rate('us:SC', '5', 'standard', 'services.cultural_admission', 'admissions'),
        // Virginia's retail sales tax does not reach telecommunications; its
        // communications tax does, at 5%, except on coin-operated calls.
        schema28Rate('us:VA', '5.3'),
        schema28Rate('us:VA', '0', 'exempt', 'services.telecom'),
        schema28Rate('us:VA', '5', 'standard', 'services.telecom', 'communications'),
        schema28Rate('us:VA', '0', 'exempt', 'services.telecom.coin_operated', 'communications'),
        // Texas: no sales tax on a room, and the state hotel tax on one of $15 a day
        // or more (Tax Code ch. 156); and SaaS on 80% of the price.
        schema28Rate('us:TX', '6.25'),
        schema28Rate('us:TX', '0', 'exempt', 'services.accommodation'),
        schema28Rate('us:TX', '6', 'standard', 'services.accommodation', 'lodging', ['conditions' => [[
            'kind' => 'applies_only_to',
            'says' => 'a room that costs $15 or more each day',
            'predicate' => ['fact' => 'charge.accommodationRatePerDayUsd', 'op' => 'at_least', 'value' => '15', 'says' => '$15 or more each day'],
        ]]]),
        // Arizona's transaction privilege tax, and its 911 excise: twenty cents per
        // account per month, which is no percentage of a sale.
        schema28Rate('us:AZ', '5.6', taxType: 'gross_receipts', extra: ['borneBy' => 'supplier', 'mayBePassedOn' => true]),
        schema28Rate('us:AZ', '0', 'standard', 'services.telecom', 'excise', [
            'borneBy' => 'supplier', 'mayBePassedOn' => true, 'percentage' => null,
            'perUnit' => ['amount' => '0.20', 'currency' => 'USD', 'per' => 'activated wire or wireless service account, per month'],
        ]),
        schema28Rate('us:HI', '4'),
        schema28Rate('us:CT', '6.35'),
    ];

    $rules = [
        schema28Rule('us:TX', 'taxable_base', [
            'component' => 'supplied_item', 'included' => true, 'category' => 'services.digital.saas',
            'proportion' => '80', 'localTaxes' => 'same_share',
        ]),
        schema28Rule('us:TX', 'read_unstated', [
            'category' => 'services.cultural_admission',
            'finding' => 'Chapter 151 names no admission as a taxable service.',
            'sections' => ['Tax Code § 151.0101'],
            'readOn' => '2026-11-01',
            'readOnIsCeiling' => true,
        ], ['source' => 'us-tx-tax-code-151']),
        schema28Rule('us:HI', 'threshold', [
            'amount' => '100000.00', 'currency' => 'USD', 'measuredOver' => 'previous_or_current_calendar_year',
            'transactions' => 200, 'combinator' => 'sales_or_transactions', 'binds' => 'remote_seller',
            'amountOperator' => 'at_least', 'transactionsOperator' => 'at_least', 'crossing' => 'must_register',
            'obligations' => [[
                'action' => 'remit', 'trigger' => 'first_crossing_in_current_year',
                'date' => ['kind' => 'return_due_after_following_filing_period', 'days' => 20, 'annualReturnMonth' => 4],
                'says' => 'the taxpayer must file its first periodic return by the deadline for the periodic return following the period in which the taxpayer met the threshold.',
            ]],
        ]),
        schema28Rule('us:CT', 'threshold', [
            'amount' => '100000.00', 'currency' => 'USD', 'measuredOver' => 'twelve_months_ended_september_30',
            'transactions' => 200, 'combinator' => 'sales_and_transactions', 'binds' => 'remote_seller',
            'amountOperator' => 'at_least', 'transactionsOperator' => 'at_least', 'crossing' => 'must_register',
            'obligations' => [[
                'action' => 'collect', 'trigger' => 'measurement_window_end',
                'date' => ['kind' => 'first_day_of_nth_following_month', 'months' => 1],
                'says' => 'during the twelve-month period ended on the September thirtieth immediately preceding the monthly or quarterly period',
            ]],
        ]),
        ...$extraRules,
    ];

    $responses = [
        $base => Http::response([
            'version' => SCHEMA_28_RELEASE,
            'schemaVersion' => '2.8.0',
            'links' => ['regions' => [$base.'/regions/us']],
        ]),
        $base.'/sections/regimes' => Http::response(['regimes' => [[
            'regime' => 'us',
            'members' => array_map(static fn (string $state, string $name): array => [
                'code' => 'us:'.$state, 'name' => $name, 'isoCode' => $state, 'from' => null, 'until' => null, 'leviesNoTax' => false,
            ], array_keys($states), $states),
        ]]]),
        $base.'/sections/categories' => Http::response(['categories' => array_map(static fn (array $c): array => [
            'key' => $c[0], 'name' => $c[0], 'parent' => $c[1], 'description' => null, 'regions' => [], 'cites' => null,
        ], $categories)]),
        $base.'/sections/rules' => Http::response(['rules' => $rules]),
        $base.'/coverage' => Http::response([]),
        $base.'/regions/us' => Http::response([
            'rates' => $rates,
            'jurisdictions' => array_map(static fn (string $state, string $name): array => [
                'code' => 'us:'.$state, 'name' => $name, 'level' => 'state', 'parent' => null, 'inTaxArea' => true,
            ], array_keys($states), $states),
        ]),
    ];

    foreach (['mappings', 'sources', 'standard-rates'] as $section) {
        $responses[$base.'/sections/'.$section] = Http::response([]);
    }

    Http::fake([...$responses, $base.'/*' => Http::response([], 404)]);
}

function installSchema28Register(array $extraRules = []): void
{
    publishSchema28Register($extraRules);

    $result = app(Compiler::class)->compile(SCHEMA_28_RELEASE, ['us'], null, false, [], static fn (): null => null);
    app(StorePointer::class)->pointAt($result['version']);
    app()->forgetInstance(RegisterDataset::class);
}

function schema28Query(string $state, string $amount, string $key, array $facts = []): TaxQuery
{
    $country = new CountryCode('US');
    $subdivision = new SubdivisionCode('US-'.$state);

    return new TaxQuery(
        amount: Money::of($amount, 'USD'),
        pricing: Pricing::Exclusive,
        place: app(JurisdictionRepository::class)->find($country, $subdivision),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations($country, [new SellerRegistration($country, $subdivision)]),
        suppliedAt: new DateTimeImmutable('2026-11-14'),
        categoryKey: $key,
        facts: new DecisionFacts($facts),
    );
}

beforeEach(function (): void {
    config()->set('tax.register.store', sys_get_temp_dir().'/cbox-tax-schema28-'.getmypid().'-'.bin2hex(random_bytes(4)));
    Http::preventStrayRequests();
});

it('compiles a 2.8.0 release carrying every shape 2.8 adds, and names no rule kind as unread', function (): void {
    publishSchema28Register();
    $said = [];

    app(Compiler::class)->compile(SCHEMA_28_RELEASE, ['us'], null, false, [], static function (string $line) use (&$said): null {
        $said[] = $line;

        return null;
    });

    $manifest = json_decode((string) file_get_contents(app(StoreLayout::class)->manifest(SCHEMA_28_RELEASE)), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['schemaVersion'])->toBe('2.8.0')
        ->and(implode("\n", $said))->not->toContain('this engine does not read');
});

it('adds South Carolina\'s admissions tax beside its sales-tax exemption', function (): void {
    installSchema28Register();

    $a = app(TaxCalculator::class)->assess(schema28Query('SC', '100.00', 'services.cultural_admission'));

    // 0% sales tax and 5% admissions tax: not the 6% general rate, not 5% sales tax.
    // A state-level answer has no breakdown, with or without a levy beside it.
    expect((string) $a->rate?->percentage)->toBe('5')
        ->and($a->treatment)->toBe(TaxTreatment::Standard)
        ->and($a->rate?->confidence)->toBe(Confidence::Authoritative)
        ->and((string) $a->tax->getAmount())->toBe('5.00')
        ->and($a->rate?->components)->toBe([])
        ->and($a->breakdown)->toBeNull();
});

it('leaves a supply the levy does not name on the jurisdiction\'s own answer', function (): void {
    installSchema28Register();

    $a = app(TaxCalculator::class)->assess(schema28Query('SC', '100.00', 'services'));

    expect((string) $a->tax->getAmount())->toBe('6.00')
        ->and($a->rate?->components)->toBe([]);
});

it('lets a narrower exempt row of the levy answer its own category', function (): void {
    installSchema28Register();

    $telecom = app(TaxCalculator::class)->assess(schema28Query('VA', '100.00', 'services.telecom'));
    $coin = app(TaxCalculator::class)->assess(schema28Query('VA', '100.00', 'services.telecom.coin_operated'));

    expect((string) $telecom->tax->getAmount())->toBe('5.00')
        ->and((string) $coin->tax->getAmount())->toBe('0.00')
        ->and($coin->treatment)->toBe(TaxTreatment::Exempt);
});

it('applies a levy on its own conditions, and flags it where they are open', function (): void {
    installSchema28Register();

    $open = app(TaxCalculator::class)->assess(schema28Query('TX', '100.00', 'services.accommodation'));
    $cheap = app(TaxCalculator::class)->assess(schema28Query('TX', '100.00', 'services.accommodation', ['charge.accommodationRatePerDayUsd' => '10']));
    $room = app(TaxCalculator::class)->assess(schema28Query('TX', '100.00', 'services.accommodation', ['charge.accommodationRatePerDayUsd' => '120']));

    expect((string) $room->tax->getAmount())->toBe('6.00')
        ->and($room->rate?->limitedBy)->toBeNull()
        ->and((string) $cheap->tax->getAmount())->toBe('0.00')
        ->and($cheap->treatment)->toBe(TaxTreatment::Exempt)
        ->and((string) $open->tax->getAmount())->toBe('6.00')
        ->and($open->rate?->limitedBy)->toBe(RateLimit::ConditionsUnevaluated)
        ->and(array_merge(...array_map(
            static fn (UnsettledCondition $c): array => $c->facts,
            new RegisterRateSource(app(RegisterDataset::class))->unsettledConditions(schema28Query('TX', '1.00', 'services')->place, 'services.accommodation'),
        )))->toContain('charge.accommodationRatePerDayUsd');
});

it('says a per-unit levy is not in the figure, rather than dropping it or weighing it against the answer', function (): void {
    installSchema28Register();

    $a = app(TaxCalculator::class)->assess(schema28Query('AZ', '100.00', 'services.telecom'));

    // 5.6%, the transaction privilege tax — not the general rate flagged ambiguous.
    expect((string) $a->rate?->percentage)->toBe('5.6')
        ->and($a->rate?->limitedBy)->toBe(RateLimit::SeparateLevyUnpriced)
        ->and($a->rate?->confidence)->toBe(Confidence::Derived)
        ->and(RateLimit::SeparateLevyUnpriced->remedy())->toContain('per-unit');
});

it('still taxes a share of the price on a 2.8 release', function (): void {
    installSchema28Register();

    $a = app(TaxCalculator::class)->assess(schema28Query('TX', '100.00', 'services.digital.saas'));

    expect((string) $a->tax->getAmount())->toBe('5.00')
        ->and((string) $a->rate?->taxableShare)->toBe('80');
});

it('shows what was read and found silent, and prices exactly as without it', function (): void {
    installSchema28Register();
    $texas = schema28Query('TX', '100.00', 'services.cultural_admission');

    $readings = app(UnstatedReadings::class)->for($texas->place, 'services.cultural_admission', new DateTimeImmutable('2026-11-14'));
    $a = app(TaxCalculator::class)->assess($texas);

    expect($readings)->toHaveCount(1)
        ->and($readings[0]->jurisdiction)->toBe('us:TX')
        ->and($readings[0]->sections)->toBe(['Tax Code § 151.0101'])
        ->and($readings[0]->readOn)->toBe('2026-11-01')
        ->and($readings[0]->readOnIsCeiling)->toBeTrue()
        ->and($readings[0]->source)->toBe('us-tx-tax-code-151')
        ->and(app(UnstatedReadings::class)->for($texas->place, 'services.telecom'))->toBe([])
        // The general rate, as without the record: never 0%, never exempt.
        ->and((string) $a->tax->getAmount())->toBe('6.25');
});

it('carries Hawaii\'s first return and Connecticut\'s measuring window whole', function (): void {
    installSchema28Register();

    $hawaii = app(NexusThresholds::class)->for(new SubdivisionCode('US-HI'), new DateTimeImmutable('2026-11-14'))?->obligations[0];
    $connecticut = app(NexusThresholds::class)->for(new SubdivisionCode('US-CT'), new DateTimeImmutable('2026-11-14'))?->obligations[0];

    expect($hawaii?->dateKind)->toBe('return_due_after_following_filing_period')
        ->and($hawaii?->dateFigure)->toBe(20)
        ->and($hawaii?->annualReturnMonth)->toBe(4)
        ->and($connecticut?->trigger)->toBe('measurement_window_end')
        ->and($connecticut?->dateKind)->toBe('first_day_of_nth_following_month')
        ->and($connecticut?->dateFigure)->toBe(1);
});

it('names each separate levy\'s tax type on the records a host reads', function (): void {
    installSchema28Register();

    $records = app(TaxRegister::class)->rateRecords(app(JurisdictionRepository::class)->find(new CountryCode('US'), new SubdivisionCode('US-SC')));

    expect(array_map(static fn (RateRecord $r): ?string => $r->taxType, $records))->toBe(['sales_use', 'sales_use', 'admissions']);
});

it('refuses an obligation shape it has not been told about, rather than dropping it', function (array $date, string $trigger): void {
    $register = FakeRegister::at(config('tax.register.store'))->rate('us:HI', '4');
    $register->rule('us:HI', 'threshold', [
        'amount' => '100000.00', 'currency' => 'USD', 'binds' => 'remote_seller', 'combinator' => 'sales_only',
        'obligations' => [['action' => 'remit', 'trigger' => $trigger, 'date' => $date, 'says' => 'Some later schema.']],
    ], '2000-01-01')->install();

    expect(fn () => app(NexusThresholds::class)->for(new SubdivisionCode('US-HI'), new DateTimeImmutable('2026-11-14')))
        ->toThrow(UnresolvedTaxRule::class);
})->with([
    'an unknown date kind' => [['kind' => 'return_due_after_next_quarter', 'days' => 20], 'threshold_met'],
    'an unknown trigger' => [['kind' => 'at_trigger'], 'fiscal_year_end'],
    'the annual month left out' => [['kind' => 'return_due_after_following_filing_period', 'days' => 20], 'threshold_met'],
    'a figure the kind does not carry' => [['kind' => 'at_trigger', 'days' => 3], 'threshold_met'],
    'an annual month out of range' => [['kind' => 'return_due_after_following_filing_period', 'days' => 20, 'annualReturnMonth' => 13], 'threshold_met'],
]);

it('refuses a read-unstated record carrying a field it has not been reviewed against', function (): void {
    publishSchema28Register([schema28Rule('us:TX', 'read_unstated', [
        'category' => 'services', 'finding' => 'silent', 'sections' => ['§ 1'], 'readOn' => '2026-11-01', 'rate' => '0',
    ])]);

    expect(fn () => app(Compiler::class)->compile(SCHEMA_28_RELEASE, ['us'], null, false, [], static fn (): null => null))
        ->toThrow(UnresolvedTaxRule::class, 'payload.rate');
});

it('refuses a levy whose rows disagree at one category, having no general rate to fall back to', function (): void {
    FakeRegister::at(config('tax.register.store'))
        ->category('services')->category('services.cultural_admission', 'services')
        ->rate('us:SC', '6', from: '2000-01-01')
        ->rate('us:SC', '5', category: 'services.cultural_admission', from: '2000-01-01', extra: ['taxType' => 'admissions'])
        ->rate('us:SC', '4', category: 'services.cultural_admission', from: '2000-01-01', extra: ['taxType' => 'admissions'])
        ->install();

    expect(fn () => app(TaxCalculator::class)->assess(schema28Query('SC', '100.00', 'services.cultural_admission')))
        ->toThrow(UnresolvedTaxRule::class, 'more than one admissions rate');
});

it('refuses a levy beside a rate on a share of the price, whose base nobody published', function (): void {
    FakeRegister::at(config('tax.register.store'))
        ->category('services')->category('services.digital', 'services')
        ->rate('us:TX', '6.25', from: '2000-01-01')
        ->rate('us:TX', '2', category: 'services.digital', from: '2000-01-01', extra: ['taxType' => 'communications'])
        ->rule('us:TX', 'taxable_base', ['component' => 'supplied_item', 'included' => true, 'category' => 'services.digital', 'proportion' => '80', 'localTaxes' => 'same_share'], '2000-01-01')
        ->install();

    expect(fn () => app(TaxCalculator::class)->assess(schema28Query('TX', '100.00', 'services.digital')))
        ->toThrow(UnresolvedTaxRule::class, 'a share of the price');
});

it('leaves out a levy the supplier bears and may not pass on, as every such rate', function (): void {
    FakeRegister::at(config('tax.register.store'))
        ->category('services')->category('services.cultural_admission', 'services')
        ->rate('us:SC', '6', from: '2000-01-01')
        ->rate('us:SC', '5', category: 'services.cultural_admission', from: '2000-01-01', extra: ['taxType' => 'admissions', 'borneBy' => 'supplier', 'mayBePassedOn' => false])
        ->install();

    expect((string) app(TaxCalculator::class)->assess(schema28Query('SC', '100.00', 'services.cultural_admission'))->tax->getAmount())->toBe('6.00');
});

it('never lets a levy stand in for an answer the jurisdiction does not give', function (): void {
    // Before 2.8 the admissions row, alone at its rung, answered INSTEAD of the 6%.
    FakeRegister::at(config('tax.register.store'))
        ->category('services')->category('services.cultural_admission', 'services')
        ->rate('us:SC', '6', from: '2000-01-01')
        ->rate('us:SC', '5', category: 'services.cultural_admission', from: '2000-01-01', extra: ['taxType' => 'admissions'])
        ->install();

    $a = app(TaxCalculator::class)->assess(schema28Query('SC', '100.00', 'services.cultural_admission'));

    expect((string) $a->tax->getAmount())->toBe('11.00')
        ->and((string) $a->rate?->percentage)->toBe('11');
});

/*
 * AT A RESOLVED ADDRESS, with the localities' own rows. A category the state does not
 * tax takes no general local share, levy or not: only a local row that names the
 * category reaches it.
 */

function levyAddressQuery(string $state, string $zip, string $amount, string $key, array $facts = [], bool $elected = false): TaxQuery
{
    $country = new CountryCode('US');
    $subdivision = new SubdivisionCode('US-'.$state);

    return new TaxQuery(
        amount: Money::of($amount, 'USD'),
        pricing: Pricing::Exclusive,
        place: app(JurisdictionRepository::class)->find($country, $subdivision)->withLocality(new LocalityCode($subdivision, LocalityScheme::Zip9->value, $zip)),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations($country, [new SellerRegistration($country, $subdivision, scheme: $elected ? UsSalesTaxRegime::REMOTE_ELECTION_SCHEME : null)]),
        suppliedAt: new DateTimeImmutable('2026-11-14'),
        categoryKey: $key,
        facts: new DecisionFacts($facts),
    );
}

/** @return list<array{?string, ?string, string, string}> */
function levyLines(?TaxBreakdown $breakdown): array
{
    return array_map(static fn ($l): array => [$l->code, $l->taxType, (string) $l->percentage, (string) $l->tax->getAmount()], $breakdown->lines ?? []);
}

function southCarolinaAt29401(string $local, string $kind): FakeRegister
{
    $code = $kind === 'combined' ? 'us:SC:CITY-CHARLESTON' : 'us:SC:COUNTY-019';

    return FakeRegister::at(config('tax.register.store'))
        ->category('services')->category('services.cultural_admission', 'services')
        ->rate('us:SC', '6', from: '2000-01-01', extra: ['taxType' => 'sales_use'])->named('us:SC', 'South Carolina')
        ->rate('us:SC', '0', 'exempt', 'services.cultural_admission', from: '2000-01-01', extra: ['taxType' => 'sales_use'])
        ->rate('us:SC', '5', category: 'services.cultural_admission', from: '2000-01-01', extra: ['taxType' => 'admissions'])
        ->rate($code, $local, $kind, from: '2000-01-01', extra: ['taxType' => 'sales_use'])->named($code, 'Charleston')
        ->boundary('SC', '29401', ['state:SC', $kind === 'combined' ? 'city:CHARLESTON' : 'county:019']);
}

it('charges only the admissions tax on a South Carolina admission at an address, whatever the locality levies in general', function (string $local, string $kind, string $general): void {
    southCarolinaAt29401($local, $kind)->install();

    $admission = app(TaxCalculator::class)->assess(levyAddressQuery('SC', '29401-0001', '100.00', 'services.cultural_admission'));
    $other = app(TaxCalculator::class)->assess(levyAddressQuery('SC', '29401-0001', '100.00', 'services'));

    expect((string) $admission->tax->getAmount())->toBe('5.00')
        ->and($admission->treatment)->toBe(TaxTreatment::Standard)
        ->and($admission->rate?->limitedBy)->toBeNull()
        ->and(levyLines($admission->breakdown))->toBe([['us:SC', null, '0', '0.00'], ['us:SC', 'admissions', '5', '5.00']])
        ->and($admission->breakdown?->lines[1]->label())->toBe('South Carolina admissions tax')
        // The locality's general share still reaches what the state taxes.
        ->and((string) $other->tax->getAmount())->toBe($general);
})->with([
    'an untyped county share' => ['2', 'local_component', '8.00'],
    'a city\'s all-in combined rate' => ['9', 'combined', '9.00'],
]);

it('keeps the levy apart from the sales tax in an order\'s totals per authority', function (): void {
    southCarolinaAt29401('2', 'local_component')->install();
    $query = levyAddressQuery('SC', '29401-0001', '100.00', 'services');

    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder($query->place, $query->customer, $query->seller, Pricing::Exclusive, [
        new SupplyLine('ticket', Money::of('100.00', 'USD'), categoryKey: 'services.cultural_admission'),
        new SupplyLine('ticket-2', Money::of('40.00', 'USD'), categoryKey: 'services.cultural_admission'),
        new SupplyLine('parking', Money::of('10.00', 'USD'), categoryKey: 'services'),
    ], suppliedAt: $query->suppliedAt));

    $totals = array_map(static fn ($t): array => [$t->code, $t->taxType, (string) $t->tax->getAmount()], $order->taxByAuthority() ?? []);

    expect($totals)->toBe([['us:SC', null, '0.60'], ['us:SC', 'admissions', '7.00'], ['us:SC:COUNTY-019', null, '0.20']]);
});

function texasRoomsAt78701(): FakeRegister
{
    return FakeRegister::at(config('tax.register.store'))
        ->category('services')->category('services.accommodation', 'services')
        ->rate('us:TX', '6.25', from: '2000-01-01', extra: ['taxType' => 'sales_use'])
        ->rate('us:TX', '0', 'exempt', 'services.accommodation', from: '2000-01-01', extra: ['taxType' => 'sales_use'])
        ->rate('us:TX', '6', category: 'services.accommodation', from: '2000-01-01', extra: ['taxType' => 'lodging', 'conditions' => [[
            'kind' => 'applies_only_to',
            'says' => 'a room that costs $15 or more each day',
            'predicate' => ['fact' => 'charge.accommodationRatePerDayUsd', 'op' => 'at_least', 'value' => '15', 'says' => '$15 or more each day'],
        ]]])
        ->rate('us:TX:CITY-AUSTIN', '1', 'local_component', from: '2000-01-01', extra: ['taxType' => 'sales_use'])->named('us:TX:CITY-AUSTIN', 'Austin')
        ->rate('us:TX:DISTRICT-CAPMETRO', '1', 'local_component', from: '2000-01-01', extra: ['taxType' => 'sales_use'])->named('us:TX:DISTRICT-CAPMETRO', 'Capital Metro')
        ->boundary('TX', '78701', ['state:TX', 'city:AUSTIN', 'district:CAPMETRO']);
}

it('charges a Texas room in Austin the hotel tax and no city or transit sales tax', function (): void {
    texasRoomsAt78701()->install();

    $room = app(TaxCalculator::class)->assess(levyAddressQuery('TX', '78701-0001', '100.00', 'services.accommodation', ['charge.accommodationRatePerDayUsd' => '120']));
    $cheap = app(TaxCalculator::class)->assess(levyAddressQuery('TX', '78701-0001', '100.00', 'services.accommodation', ['charge.accommodationRatePerDayUsd' => '10']));
    $other = app(TaxCalculator::class)->assess(levyAddressQuery('TX', '78701-0001', '100.00', 'services'));

    expect((string) $room->tax->getAmount())->toBe('6.00')
        ->and(levyLines($room->breakdown))->toBe([['us:TX', null, '0', '0.00'], ['us:TX', 'lodging', '6', '6.00']])
        ->and((string) $cheap->tax->getAmount())->toBe('0.00')
        ->and($cheap->treatment)->toBe(TaxTreatment::Exempt)
        ->and((string) $other->tax->getAmount())->toBe('8.25');
});

it('charges Virginia telecom at an address the communications tax and no local sales tax', function (): void {
    FakeRegister::at(config('tax.register.store'))
        ->category('services')->category('services.telecom', 'services')
        ->rate('us:VA', '4.3', from: '2000-01-01', extra: ['taxType' => 'sales_use'])
        ->rate('us:VA', '0', 'exempt', 'services.telecom', from: '2000-01-01', extra: ['taxType' => 'sales_use'])
        ->rate('us:VA', '5', category: 'services.telecom', from: '2000-01-01', extra: ['taxType' => 'communications'])
        ->rate('us:VA:COUNTY-760', '1', 'local_component', from: '2000-01-01', extra: ['taxType' => 'sales_use'])->named('us:VA:COUNTY-760', 'Richmond')
        ->boundary('VA', '23219', ['state:VA', 'county:760'])
        ->install();

    $telecom = app(TaxCalculator::class)->assess(levyAddressQuery('VA', '23219-0001', '100.00', 'services.telecom'));

    expect((string) $telecom->tax->getAmount())->toBe('5.00')
        ->and(levyLines($telecom->breakdown))->toBe([['us:VA', null, '0', '0.00'], ['us:VA', 'communications', '5', '5.00']]);
});

it('refuses an elected rate on a supply a separate levy reaches, whatever the levy\'s conditions', function (string $perDay): void {
    texasRoomsAt78701()->rule('us:TX', 'remote_seller_election', [
        'program' => 'Single Local Use Tax Rate', 'mechanic' => 'single_local_rate',
        'ratePercent' => '1.75', 'statute' => 'Tex. Tax Code § 151.0595',
    ], '2000-01-01')->install();

    expect(fn () => app(TaxCalculator::class)->assess(levyAddressQuery('TX', '78701-0001', '100.00', 'services.accommodation', ['charge.accommodationRatePerDayUsd' => $perDay], elected: true)))
        ->toThrow(UnresolvedTaxRule::class, 'lodging');

    // What the election does cover is still priced by it.
    expect((string) app(TaxCalculator::class)->assess(levyAddressQuery('TX', '78701-0001', '100.00', 'services', elected: true))->tax->getAmount())->toBe('8.00');
})->with(['a room of $120 a day' => '120', 'a room of $10 a day' => '10']);

it('keeps a state-level answer without a breakdown when a levy is added, so no return drops the county', function (): void {
    FakeRegister::at(config('tax.register.store'))
        ->category('services')->category('services.accommodation', 'services')
        ->rate('us:HI', '4', from: '2000-01-01', extra: ['taxType' => 'gross_receipts', 'borneBy' => 'supplier', 'mayBePassedOn' => true])
        ->rate('us:HI', '10.25', category: 'services.accommodation', from: '2000-01-01', extra: ['taxType' => 'lodging', 'borneBy' => 'supplier', 'mayBePassedOn' => true])
        ->rate('us:HI:COUNTY-003', '0.5', 'local_component', from: '2000-01-01', extra: ['taxType' => 'gross_receipts', 'borneBy' => 'supplier', 'mayBePassedOn' => true])
        ->boundary('HI', '96813', ['state:HI', 'county:003'])
        ->install();
    $place = levyAddressQuery('HI', '96813-0001', '100.00', 'services.accommodation')->place;
    $query = new TaxQuery(Money::of('100.00', 'USD'), Pricing::Exclusive, app(JurisdictionRepository::class)->find($place->country, $place->subdivision), CustomerType::Consumer,
        new SellerRegistrations($place->country, [new SellerRegistration($place->country, $place->subdivision)]), suppliedAt: new DateTimeImmutable('2026-11-14'), categoryKey: 'services.accommodation');

    $room = app(TaxCalculator::class)->assess($query);
    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder($query->place, $query->customer, $query->seller, Pricing::Exclusive, [
        new SupplyLine('room', Money::of('100.00', 'USD'), categoryKey: 'services.accommodation'),
    ], suppliedAt: $query->suppliedAt));

    expect((string) $room->tax->getAmount())->toBe('14.25')
        ->and($room->rate?->limitedBy)->toBe(RateLimit::NoLocalResolution)
        ->and($room->rate?->components)->toBe([])
        ->and($order->taxByAuthority())->toBeNull();
});
