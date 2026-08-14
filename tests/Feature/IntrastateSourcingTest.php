<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Sourcing\UsTaxDatasetSourcing;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyRoute;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

// Nine states tax an IN-STATE sale at the seller's location, not the buyer's.
// Texas is one, and it is the volume case: a Houston seller shipping across
// Houston owes the Houston rate. The engine shipped SourcingRules bound, backed by
// a whole dataset section, and read by nothing — because TaxQuery had no field for
// where the seller was.

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->dataset = new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/us-tax-dataset',
    );
});

/** A US place at a named taxing authority, supplied directly rather than via ZIP+4. */
function atAuthority(string $state, string $code): Jurisdiction
{
    return test()->geo->find(new CountryCode('US'), new SubdivisionCode($state))
        ->withLocality(new LocalityCode(new SubdivisionCode($state), 'sst-fips', $code));
}

function sourcingCalculator(UsTaxDataset $dataset): DefaultTaxCalculator
{
    return new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(
            new StaticProductTaxability,
            test()->geo,
            null,
            new UsTaxDatasetSourcing($dataset),
        ),
        new UsTaxDatasetRateSource($dataset),
    );
}

function intrastate(string $state, string $buyerCode, ?string $sellerCode): TaxQuery
{
    return new TaxQuery(
        amount: Money::of('1000.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: atAuthority($state, $buyerCode),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode($state)),
        ]),
        route: new SupplyRoute(shipFrom: $sellerCode === null ? null : atAuthority($state, $sellerCode)),
    );
}

it('taxes an in-state Texas sale at the SELLER location', function () {
    // Texas city 2109064 levies 1.5%; county 4109000 levies 0.5%. Both on 6.25%
    // state. Sourced at the buyer this is 6.75%; Texas wants the seller's 7.75%.
    $assessment = sourcingCalculator($this->dataset)
        ->assess(intrastate('US-TX', buyerCode: '4109000', sellerCode: '2109064'));

    expect((string) $assessment->rate?->percentage)->toBe('7.75')
        ->and((string) $assessment->tax->getAmount())->toBe('77.50')
        ->and($assessment->reason)->toContain("seller's location");
});

it('falls back to the buyer when the seller location is not supplied', function () {
    // Previous behaviour, preserved exactly: a caller that supplies no route is
    // destination-sourced, which is what every caller got before this existed.
    $assessment = sourcingCalculator($this->dataset)
        ->assess(intrastate('US-TX', buyerCode: '4109000', sellerCode: null));

    expect((string) $assessment->rate?->percentage)->toBe('6.75')
        ->and($assessment->reason)->not->toContain("seller's location");
});

it('ignores the seller location in a destination-sourced state', function () {
    // Kansas sources at the buyer. Supplying an origin must change nothing.
    $withOrigin = sourcingCalculator($this->dataset)
        ->assess(intrastate('US-KS', buyerCode: '209', sellerCode: '36000'));

    $withoutOrigin = sourcingCalculator($this->dataset)
        ->assess(intrastate('US-KS', buyerCode: '209', sellerCode: null));

    expect((string) $withOrigin->rate?->percentage)
        ->toBe((string) $withoutOrigin->rate?->percentage);
});

it('does not origin-source an INTERSTATE supply', function () {
    // Interstate is destination-sourced everywhere, without exception. A Kansas
    // seller shipping into Texas is taxed where the buyer is, whatever Texas says
    // about its own in-state sales.
    $query = new TaxQuery(
        amount: Money::of('1000.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: atAuthority('US-TX', '4109000'),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-TX')),
        ]),
        route: new SupplyRoute(shipFrom: atAuthority('US-KS', '36000')),
    );

    expect((string) sourcingCalculator($this->dataset)->assess($query)->rate?->percentage)->toBe('6.75');
});

it('leaves a mixed-sourcing state on destination until the split is modelled', function () {
    // California is hybrid: state, county and city origin-sourced, districts
    // destination-sourced. One place cannot express that, and picking either would
    // be wrong for half the stack — so it stays where it was and the note in the
    // dataset says why.
    $sourcing = new UsTaxDatasetSourcing($this->dataset);

    expect($sourcing->for(new SubdivisionCode('US-CA'))?->mode->value)->toBe('mixed')
        ->and($sourcing->for(new SubdivisionCode('US-TX'))?->mode->value)->toBe('origin');
});

it('falls back to destination when no sourcing source is bound at all', function () {
    // The dataset can be disabled, and then there are no intrastate rules to read.
    // That must degrade to the previous behaviour, not refuse.
    $calculator = new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(new StaticProductTaxability, $this->geo),
        new UsTaxDatasetRateSource($this->dataset),
    );

    expect((string) $calculator->assess(intrastate('US-TX', '4109000', '2109064'))->rate?->percentage)
        ->toBe('6.75');
});
