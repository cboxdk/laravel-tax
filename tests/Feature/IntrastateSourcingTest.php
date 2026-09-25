<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterSourcing;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Taxability\AlwaysTaxable;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyRoute;
use Cbox\Tax\ValueObjects\TaxQuery;

// Nine states tax an IN-STATE sale at the seller's location, not the buyer's.
// Texas is one, and it is the volume case: a Houston seller shipping across
// Houston owes the Houston rate. The engine shipped SourcingRules bound, backed by
// a whole dataset section, and read by nothing — because TaxQuery had no field for
// where the seller was.

beforeEach(function (): void {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->dataset = app(RegisterDataset::class);
});

/** A US place at a named taxing authority, supplied directly rather than via ZIP+4. */
function atAuthority(string $state, string $code): Jurisdiction
{
    return test()->geo->find(new CountryCode('US'), new SubdivisionCode($state))
        ->withLocality(new LocalityCode(new SubdivisionCode($state), 'sst-fips', $code));
}

function sourcingCalculator(RegisterDataset $dataset): DefaultTaxCalculator
{
    return new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(
            new AlwaysTaxable,
            test()->geo,
            null,
            new RegisterSourcing($dataset),
        ),
        app(TaxRateSource::class),
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

it('taxes an in-state Texas sale at the SELLER location', function (): void {
    // Texas city 2109064 levies 1.5%; county 4109000 levies 0.5%. Both on 6.25%
    // state. Sourced at the buyer this is 6.75%; Texas wants the seller's 7.75%.
    $assessment = sourcingCalculator($this->dataset)
        ->assess(intrastate('US-TX', buyerCode: '4109000', sellerCode: '2109064'));

    expect((string) $assessment->rate?->percentage)->toBe('7.75')
        ->and((string) $assessment->tax->getAmount())->toBe('77.50')
        ->and($assessment->reason)->toContain("seller's location");
});

it('falls back to the buyer when the seller location is not supplied', function (): void {
    // Previous behaviour, preserved exactly: a caller that supplies no route is
    // destination-sourced, which is what every caller got before this existed.
    $assessment = sourcingCalculator($this->dataset)
        ->assess(intrastate('US-TX', buyerCode: '4109000', sellerCode: null));

    expect((string) $assessment->rate?->percentage)->toBe('6.75')
        ->and($assessment->reason)->not->toContain("seller's location");
});

it('ignores the seller location in a destination-sourced state', function (): void {
    // Kansas sources at the buyer. Supplying an origin must change nothing.
    $withOrigin = sourcingCalculator($this->dataset)
        ->assess(intrastate('US-KS', buyerCode: '209', sellerCode: '36000'));

    $withoutOrigin = sourcingCalculator($this->dataset)
        ->assess(intrastate('US-KS', buyerCode: '209', sellerCode: null));

    expect((string) $withOrigin->rate?->percentage)
        ->toBe((string) $withoutOrigin->rate?->percentage);
});

it('does not origin-source an INTERSTATE supply', function (): void {
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

it('preserves the published mixed sourcing value for the regime to handle', function (): void {
    // California is hybrid: state, county and city origin-sourced, districts
    // destination-sourced. One place cannot express that; the adapter preserves
    // the value and the regime refuses an identified mixed intrastate route.
    $sourcing = new RegisterSourcing($this->dataset);

    expect($sourcing->for(new SubdivisionCode('US-CA'))?->mode->value)->toBe('mixed')
        ->and($sourcing->for(new SubdivisionCode('US-TX'))?->mode->value)->toBe('origin');
});

it('falls back to destination when no sourcing source is bound at all', function (): void {
    // The dataset can be disabled, and then there are no intrastate rules to read.
    // That must degrade to the previous behaviour, not refuse.
    $calculator = new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(new AlwaysTaxable, $this->geo),
        app(TaxRateSource::class),
    );

    expect((string) $calculator->assess(intrastate('US-TX', '4109000', '2109064'))->rate?->percentage)
        ->toBe('6.75');
});
