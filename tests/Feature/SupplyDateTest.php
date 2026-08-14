<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxExemption;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;

// The dated windows the coverage docs record are the real vectors here: Türkiye
// 18% → 20% on 10 July 2023, verified from the Revenue Administration's own
// publication. Everything below goes through TaxCalculator, not the rate source —
// the point is that the date now SURVIVES the journey.

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

function supplyOn(?string $date, ?TaxExemption $exemption = null, string $country = 'TR'): TaxQuery
{
    return new TaxQuery(
        amount: Money::of('100.00', 'TRY'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode($country)),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode($country)),
        exemption: $exemption,
        suppliedAt: $date === null ? null : new DateTimeImmutable($date),
    );
}

it('reprices a historical supply through the calculator, not just the source', function () {
    // Before this, every dated window in the package was unreachable from
    // TaxCalculator: the sources all accepted a date and nothing ever passed one,
    // so a credit note against a 2023 invoice quietly repriced at today's rate.
    $before = $this->tax->assess(supplyOn('2023-07-09'));
    $after = $this->tax->assess(supplyOn('2023-07-10'));

    expect((string) $before->rate?->percentage)->toBe('18')
        ->and((string) $before->tax->getAmount())->toBe('18.00')
        ->and((string) $after->rate?->percentage)->toBe('20')
        ->and((string) $after->tax->getAmount())->toBe('20.00');
});

it('treats an absent supply date as today, which is right for a fresh invoice', function () {
    $undated = $this->tax->assess(supplyOn(null));

    expect((string) $undated->rate?->percentage)->toBe('20');
});

it('carries the date across the other primary-source-verified changes', function () {
    expect((string) $this->tax->assess(supplyOn('2020-06-30', country: 'SA'))->rate?->percentage)->toBe('5')
        ->and((string) $this->tax->assess(supplyOn('2020-07-01', country: 'SA'))->rate?->percentage)->toBe('15')
        ->and((string) $this->tax->assess(supplyOn('2021-12-31', country: 'BH'))->rate?->percentage)->toBe('5')
        ->and((string) $this->tax->assess(supplyOn('2022-01-01', country: 'BH'))->rate?->percentage)->toBe('10');
});

// ---- The exemption is tested at the tax point, not at calculation time ----

it('honours a certificate that was valid when the supply was made', function () {
    // The certificate expired last year. The supply happened while it was live, so
    // a credit note raised today must not retroactively un-exempt it — the seller
    // would owe tax it correctly never charged.
    $exemption = $this->taxExemption(
        countries: ['TR'],
        validFrom: new DateTimeImmutable('2023-01-01'),
        validUntil: new DateTimeImmutable('2023-12-31'),
    );

    $assessment = $this->tax->assess(supplyOn('2023-06-01', $exemption));

    $this->assertExempt($assessment);
});

it('does not let an expired certificate exempt a supply made after it lapsed', function () {
    $exemption = $this->taxExemption(
        countries: ['TR'],
        validFrom: new DateTimeImmutable('2023-01-01'),
        validUntil: new DateTimeImmutable('2023-12-31'),
    );

    $assessment = $this->tax->assess(supplyOn('2024-06-01', $exemption));

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and($assessment->exemption)->toBeNull();
});

it('does not let a not-yet-valid certificate exempt an earlier supply', function () {
    $exemption = $this->taxExemption(
        countries: ['TR'],
        validFrom: new DateTimeImmutable('2024-01-01'),
    );

    expect($this->tax->assess(supplyOn('2023-06-01', $exemption))->treatment)
        ->toBe(TaxTreatment::Standard);
});

// ---- The registration that gates the whole US regime has a lifetime -------

it('does not apply a registration to supplies made before it existed', function () {
    // The day-one failure of every migration: a customer backfills last year's
    // invoices to build their first return, and every one of them gets taxed
    // against a registration that did not exist yet.
    $seller = new SellerRegistrations(new CountryCode('US'), [
        new SellerRegistration(
            new CountryCode('US'),
            new SubdivisionCode('US-CA'),
            validFrom: new DateTimeImmutable('2026-03-01'),
        ),
    ]);

    $before = usSupply($seller, '2026-02-15');
    $after = usSupply($seller, '2026-03-15');

    expect($before->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and((string) $before->tax->getAmount())->toBe('0.00')
        ->and($after->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $after->tax->getAmount())->toBe('7.25');
});

it('stops applying a registration after it is surrendered', function () {
    $seller = new SellerRegistrations(new CountryCode('US'), [
        new SellerRegistration(
            new CountryCode('US'),
            new SubdivisionCode('US-CA'),
            validUntil: new DateTimeImmutable('2026-06-30'),
        ),
    ]);

    expect(usSupply($seller, '2026-06-30')->treatment)->toBe(TaxTreatment::Standard)
        ->and(usSupply($seller, '2026-07-01')->treatment)->toBe(TaxTreatment::NotRegistered);
});

it('treats an undated registration as always in force', function () {
    // The old behaviour, preserved: leaving both bounds null changes nothing.
    $seller = new SellerRegistrations(new CountryCode('US'), [
        new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-CA')),
    ]);

    expect(usSupply($seller, '2019-01-01')->treatment)->toBe(TaxTreatment::Standard);
});

// ---- The date used is recorded, so the answer can be audited --------------

it('stamps the tax point it resolved against onto the assessment', function () {
    // Without this the date fix is invisible in the output: a rate, a registration
    // and a certificate were all judged as of some date, and nobody could tell which.
    $dated = $this->tax->assess(supplyOn('2023-07-09'));
    $undated = $this->tax->assess(supplyOn(null));

    expect($dated->taxPoint?->format('Y-m-d'))->toBe('2023-07-09')
        ->and($undated->taxPoint?->format('Y-m-d'))->toBe(new DateTimeImmutable()->format('Y-m-d'));
});

it('keeps the tax point when a buyer exemption rewrites the assessment', function () {
    $exemption = $this->taxExemption(countries: ['TR'], validFrom: new DateTimeImmutable('2023-01-01'));

    $assessment = $this->tax->assess(supplyOn('2023-06-01', $exemption));

    $this->assertExempt($assessment);
    expect($assessment->taxPoint?->format('Y-m-d'))->toBe('2023-06-01');
});

function usSupply(SellerRegistrations $seller, string $date): TaxAssessment
{
    return test()->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('US'), new SubdivisionCode('US-CA')),
        customer: CustomerType::Consumer,
        seller: $seller,
        suppliedAt: new DateTimeImmutable($date),
    ));
}

// ---- The window check actually runs now -----------------------------------

it('matches a sentinel-closed record on an UNDATED lookup', function () {
    // Kansas' records carry effectiveTo "2099-12-31" rather than null. covers()
    // used to accept only open-ended records, so on a null date NOTHING matched and
    // every lookup fell through to whatever was first in the file — right by luck
    // while each code had one record, wrong the moment one had two.
    //
    // Passing an explicit date here would prove nothing: the old code took the
    // date branch and worked. The null is the whole point.
    $rate = new UsTaxDatasetRateSource(ksDataset())
        ->rateFor(kansasCity(), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('9.125')
        ->and($rate?->confidence->value)->toBe('authoritative');
});

it('refuses a rooftop stack whose records do not cover the supply date', function () {
    // A 2004 supply predates Wyandotte County's 2005 record. Rather than quietly
    // summing a record that did not yet exist — which the deleted file-order
    // fallback did — the stack refuses and the caller drops to the state rate.
    $rate = new UsTaxDatasetRateSource(ksDataset())
        ->rateFor(kansasCity(), TaxClass::GeneralGoods, new DateTimeImmutable('2004-01-01'));

    expect((string) $rate?->percentage)->toBe('6.5')
        ->and($rate?->confidence->value)->toBe('derived');
});

function ksDataset(): UsTaxDataset
{
    return new UsTaxDataset(
        app(Factory::class),
        app(Repository::class),
        dirname(__DIR__).'/Fixtures/us-tax-dataset',
    );
}

function kansasCity(): Jurisdiction
{
    return test()->geo->find(new CountryCode('US'), new SubdivisionCode('US-KS'))
        ->withLocality(new LocalityCode(
            new SubdivisionCode('US-KS'),
            UsTaxDatasetRateSource::ZIP9_SCHEME,
            '66101-6200',
        ));
}
