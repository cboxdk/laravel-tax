<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\UnresolvedTaxRate;
use Cbox\Tax\Nexus\UsTaxDatasetNexus;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\Regime\UsSalesTaxRegime;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\Taxability\UsTaxDatasetTaxability;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyRoute;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->dataset = new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/us-tax-dataset',
    );
    $this->rates = new UsTaxDatasetRateSource($this->dataset);
    $this->regime = new UsSalesTaxRegime(
        new UsTaxDatasetTaxability($this->dataset, new StaticProductTaxability),
        new UsTaxDatasetNexus($this->dataset),
        null,
        $this->dataset,
    );
});

/**
 * A US supply from a seller whose state registration optionally carries the
 * remote-election scheme, optionally shipped from a given state.
 */
function electionQuery(
    string $state,
    bool $elected = true,
    ?string $shipFromState = null,
    string $on = '2026-08-20',
    TaxClass $class = TaxClass::GeneralGoods,
): TaxQuery {
    $subdivision = new SubdivisionCode($state);

    return new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('US'), $subdivision),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(
            new CountryCode('US'),
            [new SellerRegistration(
                new CountryCode('US'),
                $subdivision,
                scheme: $elected ? UsSalesTaxRegime::REMOTE_ELECTION_SCHEME : null,
            )],
        ),
        category: $class,
        suppliedAt: new DateTimeImmutable($on),
        route: $shipFromState === null ? new SupplyRoute : new SupplyRoute(
            shipFrom: test()->geo->find(new CountryCode('US'), new SubdivisionCode($shipFromState)),
        ),
    );
}

// ---------------------------------------------------------------------------
// The two mechanics
// ---------------------------------------------------------------------------

it('prices a remote Alabama sale at the flat 8% under an elected SSUT', function () {
    $assessment = $this->regime->assess(electionQuery('US-AL'), $this->rates);

    // Flat total: 8% replaces the whole state+local stack — never AL's 4%
    // state floor, never a locality stack.
    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $assessment->rate?->percentage)->toBe('8')
        ->and($assessment->rate->source)->toBe('us-tax-data:election')
        ->and($assessment->tax->getAmount()->__toString())->toBe('8.00')
        ->and($assessment->reason)->toContain('Simplified Sellers Use Tax')
        ->and($assessment->reason)->toContain('40-23-193');
});

it('prices a remote Texas sale at state plus the single local rate', function () {
    $assessment = $this->regime->assess(electionQuery('US-TX'), $this->rates);

    // 6.25% state (from the dataset, not hard-coded) + 1.75% elected local.
    expect((string) $assessment->rate?->percentage)->toBe('8')
        ->and($assessment->tax->getAmount()->__toString())->toBe('8.00')
        ->and($assessment->reason)->toContain('Single Local Use Tax Rate')
        ->and($assessment->reason)->toContain('6.25% state plus the 1.75% single local rate');
});

// ---------------------------------------------------------------------------
// The election is the seller's, and only a remote supply is covered
// ---------------------------------------------------------------------------

it('prices the ordinary path when the seller has not elected', function () {
    $assessment = $this->regime->assess(electionQuery('US-TX', elected: false), $this->rates);

    // Opt-in by construction: without the scheme nothing changes — the state
    // floor answers exactly as before this feature existed.
    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and($assessment->rate?->source)->not->toBe('us-tax-data:election')
        ->and((string) $assessment->rate?->percentage)->toBe('6.25');
});

it('bypasses the election for a supply shipped from inside the state', function () {
    $assessment = $this->regime->assess(electionQuery('US-TX', shipFromState: 'US-TX'), $this->rates);

    // Shipped from within Texas the seller is not remote for this supply — the
    // physical presence the program excludes — so the ordinary path prices it.
    expect($assessment->rate?->source)->not->toBe('us-tax-data:election');
});

// ---------------------------------------------------------------------------
// Refusals: an asserted election nothing can price
// ---------------------------------------------------------------------------

it('refuses a Texas supply dated outside the published determination', function () {
    // The 2026 figure expires 2026-12-31; pricing 2027 with it would charge a
    // rate nobody published, and pricing as if unelected would charge rates the
    // election replaced.
    $this->regime->assess(electionQuery('US-TX', on: '2027-02-01'), $this->rates);
})->throws(UnresolvedTaxRate::class, 'elected');

it('refuses an asserted election in a state that publishes no scheme', function () {
    $this->regime->assess(electionQuery('US-NY'), $this->rates);
})->throws(UnresolvedTaxRate::class, 'elected');

// ---------------------------------------------------------------------------
// The gates above the rate still speak first
// ---------------------------------------------------------------------------

it('still exempts a category the state does not tax before any election rate', function () {
    // SaaS is not taxable in California; an elected scheme (none exists there,
    // but the gate order is the point) must never turn exempt into 8%.
    $assessment = $this->regime->assess(
        electionQuery('US-CA', class: TaxClass::DigitalService),
        $this->rates,
    );

    expect($assessment->treatment)->not->toBe(TaxTreatment::Standard);
});
