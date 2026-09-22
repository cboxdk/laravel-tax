<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\DeliveryRules;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Enums\ApportionmentBasis;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\DeliveryComponent;
use Cbox\Tax\Enums\NexusCombinator;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\SourcingMode;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\ValueObjects\DeliveryCharge;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;

/** Independent, dated expectations; the register never supplies the expected answers. */
beforeEach(function (): void {
    $this->referenceStore = sys_get_temp_dir().'/cbox-tax-reference-'.bin2hex(random_bytes(8));
    config()->set('tax.register.store', $this->referenceStore);
    config()->set('tax.register.version', null);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->referenceStore);
});

/** @param array<string, mixed> $spec */
function referenceQuery(array $spec): TaxQuery
{
    $country = new CountryCode($spec['country']);
    $subdivision = isset($spec['subdivision']) ? new SubdivisionCode($spec['subdivision']) : null;
    $place = app(JurisdictionRepository::class)->find($country, $subdivision)
        ?? throw new RuntimeException('Reference jurisdiction is not available.');

    if (isset($spec['locality'])) {
        $place = $place->withLocality(new LocalityCode(
            $subdivision ?? throw new RuntimeException('A locality needs a subdivision.'),
            $spec['locality']['scheme'],
            $spec['locality']['value'],
        ));
    }

    $registrations = array_map(
        static fn (string $code): SellerRegistration => new SellerRegistration(
            new CountryCode(substr($code, 0, 2)),
            str_contains($code, '-') ? new SubdivisionCode($code) : null,
        ),
        $spec['registrations'] ?? [],
    );

    return new TaxQuery(
        amount: Money::of($spec['amount'] ?? '0.00', $spec['currency']),
        pricing: Pricing::from($spec['pricing']),
        place: $place,
        customer: CustomerType::from($spec['customer'] ?? 'consumer'),
        seller: new SellerRegistrations(new CountryCode($spec['sellerCountry'] ?? $spec['country']), $registrations),
        category: TaxClass::from($spec['class'] ?? 'general_goods'),
        customerTaxIdValidated: $spec['customerTaxIdValidated'] ?? false,
        suppliedAt: new DateTimeImmutable($spec['suppliedAt']),
    );
}

/** @return array{net: string, tax: string, gross: string} */
function referenceAmounts(TaxAssessment $assessment): array
{
    return [
        'net' => (string) $assessment->net->getAmount(),
        'tax' => (string) $assessment->tax->getAmount(),
        'gross' => (string) $assessment->gross->getAmount(),
    ];
}

it('matches 41 independent rate, amount, treatment and order references from a real release', function (): void {
    $corpus = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/conformance/reference/2026-09-17.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($corpus['schemaVersion'])->toBe(1)
        ->and($corpus['cases'])->toHaveCount(40)
        ->and($corpus['orders'])->toHaveCount(1);

    // A release pin makes reruns reproducible; opt into a newer release to check drift.
    $release = getenv('CBOX_TAX_REFERENCE_RELEASE') ?: $corpus['release'];
    $this->artisan('tax:data:sync', [
        '--release' => $release,
        '--region' => ['eu', 'europe', 'us'],
        '--state' => ['WA', 'FL'],
    ])->assertSuccessful();
    $this->artisan('tax:data:verify')->assertSuccessful();

    $version = app(RegisterDataset::class)->requireVersion();
    $calculator = app(OrderTaxCalculator::class);
    Http::preventStrayRequests();

    foreach ($corpus['cases'] as $case) {
        $label = $case['id'].'; release '.$version.'; '.$corpus['sources'][$case['source']]['url'];
        $assessment = $calculator->assess(referenceQuery($case['query']));
        $rate = $assessment->rate;
        $actual = referenceAmounts($assessment) + [
            'ratePercentage' => $rate === null ? null : (string) $rate->percentage,
            'treatment' => $assessment->treatment->value,
            'confidence' => $rate?->confidence->value,
            'limitedBy' => $rate?->limitedBy?->value,
        ];
        $expected = $case['expect'];

        if (isset($expected['components'])) {
            // Compare components that contribute to the combined rate.
            $components = [];

            foreach ($rate?->components ?? [] as $component) {
                if ($component->percentage->isZero()) {
                    continue;
                }

                $level = $component->level->value;
                $components[$level] = (string) BigDecimal::of($components[$level] ?? '0')->plus($component->percentage);
            }

            ksort($components);
            ksort($expected['components']);
            $actual['components'] = $components;

            expect($assessment->breakdown?->total()?->isEqualTo($assessment->tax), $label)->toBeTrue();
        }

        expect($actual, $label)->toBe($expected)
            ->and($assessment->net->plus($assessment->tax)->isEqualTo($assessment->gross), $label)->toBeTrue();

        if ($rate !== null) {
            expect($rate->source, $label)->toBe('cbox-tax')
                ->and($rate->provenance?->version, $label)->toBe($version);
        }
    }

    foreach ($corpus['orders'] as $case) {
        $spec = $case['query'];
        $query = referenceQuery($spec);
        $label = $case['id'].'; release '.$version.'; '.$corpus['sources'][$case['source']]['url'];
        $order = $calculator->assessOrder(new TaxOrder(
            place: $query->place,
            customer: $query->customer,
            seller: $query->seller,
            pricing: $query->pricing,
            lines: array_map(
                static fn (array $line): SupplyLine => new SupplyLine(
                    id: $line['id'],
                    amount: Money::of($line['amount'], $spec['currency']),
                    category: TaxClass::from($line['class'] ?? 'general_goods'),
                    isDeliveryCharge: $line['delivery'] ?? false,
                ),
                $spec['lines'],
            ),
            suppliedAt: $query->suppliedAt,
            apportionment: ApportionmentBasis::from($spec['apportionment']),
        ));

        $lines = [];

        foreach ($order->lines as $line) {
            $lines[$line->id] = referenceAmounts($line->assessment);
        }

        expect([
            'net' => (string) $order->net()->getAmount(),
            'tax' => (string) $order->tax()->getAmount(),
            'gross' => (string) $order->gross()->getAmount(),
            'lines' => $lines,
        ], $label)->toBe($case['expect'])
            ->and($order->forLine('delivery')?->reason, $label)->toContain('9.00 at 13.5%', '3.00 at 25.5%');
    }
})->group('e2e', 'reference');

it('applies dated rules rounding and conditional delivery from a pinned release', function (): void {
    $corpus = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/conformance/reference/2026-09-17.json'), true, flags: JSON_THROW_ON_ERROR);
    $this->artisan('tax:data:sync', [
        '--release' => getenv('CBOX_TAX_REFERENCE_RELEASE') ?: $corpus['release'],
        '--region' => ['us'],
        '--state' => ['IL', 'KS'],
    ])->assertSuccessful();
    $this->artisan('tax:data:verify')->assertSuccessful();
    Http::preventStrayRequests();

    $on = new DateTimeImmutable('2026-09-18');
    $nexus = app(NexusThresholds::class);
    expect($nexus->for(new SubdivisionCode('US-AZ'), $on)?->salesDollars)->toBe(100000)
        ->and($nexus->for(new SubdivisionCode('US-AZ'), new DateTimeImmutable('2019-12-31'))?->salesDollars)->toBe(200000)
        ->and($nexus->for(new SubdivisionCode('US-CT'), $on)?->combinator)->toBe(NexusCombinator::SalesAndTransactions)
        ->and($nexus->for(new SubdivisionCode('US-NY'), $on)?->combinator)->toBe(NexusCombinator::SalesAndTransactions);

    $sourcing = app(SourcingRules::class);
    expect($sourcing->for(new SubdivisionCode('US-PA'), new DateTimeImmutable('2025-12-31'))?->mode)->toBe(SourcingMode::Origin)
        ->and($sourcing->for(new SubdivisionCode('US-PA'), $on)?->mode)->toBe(SourcingMode::Destination);

    $il = referenceQuery(['country' => 'US', 'subdivision' => 'US-IL', 'currency' => 'USD', 'pricing' => 'exclusive', 'registrations' => ['US-IL'], 'suppliedAt' => '2026-09-18']);
    $calculator = app(OrderTaxCalculator::class);
    $rounded = $calculator->assessOrder(new TaxOrder(
        $il->place, $il->customer, $il->seller, $il->pricing,
        [new SupplyLine('a', Money::of('0.50', 'USD')), new SupplyLine('b', Money::of('0.50', 'USD'))],
        suppliedAt: $il->suppliedAt,
    ));
    // Arithmetic regression at the published state rate, not an address-specific all-in quote.
    expect((string) $rounded->forLine('a')?->rate?->percentage)->toBe('6.25')
        ->and((string) $rounded->tax()->getAmount())->toBe('0.07')
        ->and($rounded->net()->plus($rounded->tax())->isEqualTo($rounded->gross()))->toBeTrue();

    $ks = referenceQuery(['country' => 'US', 'subdivision' => 'US-KS', 'currency' => 'USD', 'pricing' => 'exclusive', 'registrations' => ['US-KS'], 'suppliedAt' => '2026-09-17', 'locality' => ['scheme' => 'zip9', 'value' => '66101-3064']]);
    $delivered = $calculator->assessOrder(new TaxOrder(
        $ks->place, $ks->customer, $ks->seller, $ks->pricing,
        [
            new SupplyLine('goods', Money::of('100.00', 'USD')),
            new SupplyLine('delivery', Money::of('10.00', 'USD'), isDeliveryCharge: true, delivery: new DeliveryCharge(exclusionConditionsMet: true)),
        ],
        suppliedAt: $ks->suppliedAt,
    ));
    expect((string) $delivered->forLine('delivery')?->tax->getAmount())->toBe('0.00')
        ->and($delivered->forLine('delivery')?->isExempt())->toBeTrue()
        ->and($delivered->net()->plus($delivered->tax())->isEqualTo($delivered->gross()))->toBeTrue()
        ->and(app(DeliveryRules::class)->treatment(new SubdivisionCode('US-KS'), new DeliveryCharge(DeliveryComponent::Transport, goodsTaxable: true), new DateTimeImmutable('2026-09-13')))->toBeNull();
})->group('e2e', 'reference');
