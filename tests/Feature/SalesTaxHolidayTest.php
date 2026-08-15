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

it('exempts an item exactly at the cap', function () {
    expect($this->regime->assess(holidayQuery('US-TX', '100.00', '2026-08-08'), $this->rates)->treatment)
        ->toBe(TaxTreatment::Exempt);
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

it('compares the cap as a decimal, not a float', function () {
    // A cent over the line is over the line. Floats would very likely still get
    // this right at these magnitudes — which is why the guard is the test rather
    // than the arithmetic looking careful.
    expect($this->regime->assess(holidayQuery('US-TX', '100.01', '2026-08-08'), $this->rates)->treatment)
        ->toBe(TaxTreatment::Standard)
        ->and($this->regime->assess(holidayQuery('US-TX', '99.99', '2026-08-08'), $this->rates)->treatment)
        ->toBe(TaxTreatment::Exempt);
});
