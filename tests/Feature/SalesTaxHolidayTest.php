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
use Cbox\Tax\Nexus\UsTaxDatasetNexus;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\Regime\UsSalesTaxRegime;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\Taxability\UsTaxDatasetTaxability;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
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

/** A US supply of a given class, price and date. */
function holidayQuery(
    string $state,
    string $amount,
    string $on,
    TaxClass $class = TaxClass::Clothing,
    string $currency = 'USD',
): TaxQuery {
    $subdivision = new SubdivisionCode($state);

    return new TaxQuery(
        amount: Money::of($amount, $currency),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('US'), $subdivision),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(
            new CountryCode('US'),
            [new SellerRegistration(new CountryCode('US'), $subdivision)],
        ),
        category: $class,
        suppliedAt: new DateTimeImmutable($on),
    );
}

// ---------------------------------------------------------------------------
// The window
// ---------------------------------------------------------------------------

it('exempts a qualifying item during the holiday', function () {
    // Texas, back-to-school, 7-9 August, clothing at or under $100.
    $assessment = $this->regime->assess(holidayQuery('US-TX', '80.00', '2026-08-08'), $this->rates);

    expect($assessment->treatment)->toBe(TaxTreatment::Exempt)
        ->and($assessment->tax->getAmount()->toFloat())->toBe(0.0);
});

it('charges the same item the day before', function () {
    $assessment = $this->regime->assess(holidayQuery('US-TX', '80.00', '2026-08-06'), $this->rates);

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and($assessment->tax->getAmount()->toFloat())->toBeGreaterThan(0.0);
});

it('charges the same item the day after', function () {
    $assessment = $this->regime->assess(holidayQuery('US-TX', '80.00', '2026-08-10'), $this->rates);

    expect($assessment->treatment)->toBe(TaxTreatment::Standard);
});

it('includes both boundary days', function (string $date) {
    expect($this->regime->assess(holidayQuery('US-TX', '80.00', $date), $this->rates)->treatment)
        ->toBe(TaxTreatment::Exempt);
})->with(['first day' => ['2026-08-07'], 'last day' => ['2026-08-09']]);

// ---------------------------------------------------------------------------
// The cap, which is where the two mechanics would be confused
// ---------------------------------------------------------------------------

it('taxes an item exactly at a "less than" cap', function () {
    // This test asserted the opposite and was WRONG. Tex. Tax Code 151.326(a)(1)
    // exempts clothing whose sales price is "less than $100" — an item at exactly
    // $100.00 is taxable. Whether the cap itself qualifies is per statute and is not
    // uniform, and carrying one integer for both readings under-collected on every
    // item landing exactly on a "less than" line.
    expect($this->regime->assess(holidayQuery('US-TX', '100.00', '2026-08-08'), $this->rates)->treatment)
        ->toBe(TaxTreatment::Standard);
});

it('exempts a cent below it', function () {
    expect($this->regime->assess(holidayQuery('US-TX', '99.99', '2026-08-08'), $this->rates)->treatment)
        ->toBe(TaxTreatment::Exempt);
});

it('does not let a credit note qualify on its negative amount', function () {
    // Every negative is below every cap. Unguarded, a $500 coat refunded inside a
    // holiday window was assessed exempt — so nothing was credited back against the
    // tax originally collected and the seller kept the state's money.
    $assessment = $this->regime->assess(holidayQuery('US-TX', '-500.00', '2026-08-08'), $this->rates);

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and($assessment->tax->getAmount()->isNegative())->toBeTrue();
});

it('taxes an item over the cap IN FULL, not just the excess', function () {
    // The whole point. Massachusetts' permanent clothing threshold exempts the
    // first $175 of any coat and taxes the rest; a holiday cap is all-or-nothing.
    // A $101 coat in a $100-cap state is taxed on all $101, and a partial
    // exemption here would under-collect on every item over the line.
    $assessment = $this->regime->assess(holidayQuery('US-TX', '101.00', '2026-08-08'), $this->rates);

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and($assessment->net->getAmount()->toFloat())->toBe(101.0);
});

it('reads each state\'s own cap rather than a shared one', function () {
    // Ohio caps clothing at $75 where Texas caps it at $100, and both holidays run
    // the same weekend. An $80 shirt is exempt in one and taxed in the other.
    expect($this->regime->assess(holidayQuery('US-TX', '80.00', '2026-08-08'), $this->rates)->treatment)
        ->toBe(TaxTreatment::Exempt)
        ->and($this->regime->assess(holidayQuery('US-OH', '80.00', '2026-08-08'), $this->rates)->treatment)
        ->toBe(TaxTreatment::Standard);
});

// ---------------------------------------------------------------------------
// What it refuses to guess
// ---------------------------------------------------------------------------

it('charges a class the holiday does not cover', function () {
    // Texas's holiday covers clothing and footwear here. Furniture is not modelled
    // and is charged normally — over-collecting for a weekend rather than exempting
    // a supply the state taxes.
    expect($this->regime->assess(holidayQuery('US-TX', '80.00', '2026-08-08', TaxClass::Furniture), $this->rates)->treatment)
        ->toBe(TaxTreatment::Standard);
});

it('charges normally in a state that holds no holiday', function () {
    expect($this->regime->assess(holidayQuery('US-CA', '80.00', '2026-08-08'), $this->rates)->treatment)
        ->toBe(TaxTreatment::Standard);
});

it('charges rather than refusing when the line is not in dollars', function () {
    // The caps are dollar figures in state statutes, so a euro line cannot be
    // compared without an exchange rate. The threshold path THROWS on this, and
    // here it must not: a holiday is a few days of relief, and refusing the whole
    // assessment over one would break a checkout for a perfectly taxable supply.
    $assessment = $this->regime->assess(
        holidayQuery('US-TX', '80.00', '2026-08-08', TaxClass::Clothing, 'EUR'),
        $this->rates,
    );

    expect($assessment->treatment)->toBe(TaxTreatment::Standard);
});

it('names the holiday and the cap so the exemption can be defended', function () {
    $assessment = $this->regime->assess(holidayQuery('US-TX', '80.00', '2026-08-08'), $this->rates);

    expect($assessment->reason)->toContain('Back-to-School')
        ->and($assessment->reason)->toContain('$100')
        ->and($assessment->reason)->toContain('2026-08-08');
});

it('no longer carries Illinois, whose holiday is a rate cut rather than an exemption', function () {
    // Illinois drops the STATE share 6.25% -> 1.25% and leaves every local tax in
    // force. Modelled as an exemption it zeroed the whole charge — under-collecting
    // the 1.25% plus the local stack, about nine points in Chicago. Out until the
    // model can express a reduction; charging normally over-collects and refunds.
    expect($this->regime->assess(holidayQuery('US-IL', '80.00', '2026-08-10'), $this->rates)->treatment)
        ->toBe(TaxTreatment::Standard);
});
