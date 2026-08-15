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

/** A US supply, optionally made through a marketplace, optionally on a date. */
function facilitatedQuery(
    string $state,
    bool $viaMarketplace,
    bool $sellerRegistered = true,
    ?string $on = null,
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
            $sellerRegistered ? [new SellerRegistration(new CountryCode('US'), $subdivision)] : [],
        ),
        category: $class,
        suppliedAt: $on === null ? null : new DateTimeImmutable($on),
        marketplaceFacilitated: $viaMarketplace,
    );
}

// ---------------------------------------------------------------------------
// The charge
// ---------------------------------------------------------------------------

it('charges nothing on a facilitated sale, because the marketplace already did', function () {
    $assessment = $this->regime->assess(facilitatedQuery('US-WA', viaMarketplace: true), $this->rates);

    expect($assessment->treatment)->toBe(TaxTreatment::MarketplaceFacilitated)
        ->and($assessment->tax->getAmount()->toFloat())->toBe(0.0)
        ->and($assessment->gross->getAmount()->toFloat())->toBe(100.0);
});

it('charges the seller normally on a direct sale', function () {
    $assessment = $this->regime->assess(facilitatedQuery('US-WA', viaMarketplace: false), $this->rates);

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and($assessment->tax->getAmount()->toFloat())->toBeGreaterThan(0.0);
});

it('does not care whether the seller has nexus of their own', function () {
    // The marketplace's liability is not derived from the seller's presence. A
    // seller with no registration in the state still owes nothing on a facilitated
    // sale, and the reason must say WHY — `NotRegistered` would say something else
    // entirely on the same zero.
    $assessment = $this->regime->assess(
        facilitatedQuery('US-WA', viaMarketplace: true, sellerRegistered: false),
        $this->rates,
    );

    expect($assessment->treatment)->toBe(TaxTreatment::MarketplaceFacilitated);
});

// ---------------------------------------------------------------------------
// The date, which is the whole reason this is data and not a flag
// ---------------------------------------------------------------------------

it('leaves the tax with the seller before the state\'s rule took effect', function () {
    // Missouri was the last state in, on 2023-01-01. A 2022 sale there was the
    // seller's to collect, and answering from today's map would zero a real charge.
    $assessment = $this->regime->assess(
        facilitatedQuery('US-MO', viaMarketplace: true, on: '2022-06-01'),
        $this->rates,
    );

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and($assessment->tax->getAmount()->toFloat())->toBeGreaterThan(0.0);
});

it('applies it from the day the rule came in', function () {
    $assessment = $this->regime->assess(
        facilitatedQuery('US-MO', viaMarketplace: true, on: '2023-01-01'),
        $this->rates,
    );

    expect($assessment->treatment)->toBe(TaxTreatment::MarketplaceFacilitated);
});

it('leaves the tax with the seller where no date is carried', function () {
    // Arizona's published date is not trusted and Alaska has no state tax to hang
    // one on, so neither carries one. Deny-by-default sends the tax back to the
    // seller: charging twice is visible to the customer and refundable, charging
    // nothing surfaces in an audit years later.
    $assessment = $this->regime->assess(facilitatedQuery('US-AZ', viaMarketplace: true), $this->rates);

    expect($assessment->treatment)->toBe(TaxTreatment::Standard);
});

// ---------------------------------------------------------------------------
// It is not "exempt", and the difference lands on a return
// ---------------------------------------------------------------------------

it('reports an exempt supply as exempt, not as facilitated', function () {
    // A marketplace collects nothing on an exempt supply. Calling it facilitated
    // would assert a tax that was never due — a wrong return under a right charge.
    $assessment = $this->regime->assess(
        facilitatedQuery('US-WA', viaMarketplace: true, class: TaxClass::PrescriptionMedicine),
        $this->rates,
    );

    expect($assessment->treatment)->toBe(TaxTreatment::Exempt);
});

it('says tax was due, unlike the other zero-charge outcomes', function () {
    // All four charge nothing and they mean opposite things on a return. This is
    // what stops a filing from treating them alike.
    expect(TaxTreatment::MarketplaceFacilitated->taxWasDue())->toBeTrue()
        ->and(TaxTreatment::MarketplaceFacilitated->chargesTax())->toBeFalse()
        ->and(TaxTreatment::Exempt->taxWasDue())->toBeFalse()
        ->and(TaxTreatment::NotRegistered->taxWasDue())->toBeFalse()
        ->and(TaxTreatment::ZeroRated->taxWasDue())->toBeFalse()
        ->and(TaxTreatment::ReverseCharge->taxWasDue())->toBeTrue();
});

it('explains itself well enough to defend the return', function () {
    $assessment = $this->regime->assess(facilitatedQuery('US-WA', viaMarketplace: true), $this->rates);

    expect($assessment->reason)->toContain('marketplace')
        ->and($assessment->reason)->toContain('US-WA');
});
