<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\ValueObjects\ReturnPeriod;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

// A return covers a PERIOD. Until it could be given one, aggregation produced a
// total over whatever the caller happened to pass — which is a number, not a
// filing. And the date that decides the period is not always the tax point: goods
// supplied on 30 December and invoiced on 3 January are rated at December's rate
// while national rules may put them in either period.

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
    $this->returns = $this->app->make(ReturnAggregator::class);
});

function periodSupply(string $amount, ?string $suppliedAt = null, ?string $reportedOn = null): TaxQuery
{
    return new TaxQuery(
        amount: Money::of($amount, 'DKK'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
        suppliedAt: $suppliedAt === null ? null : new DateTimeImmutable($suppliedAt),
        reportedOn: $reportedOn === null ? null : new DateTimeImmutable($reportedOn),
    );
}

it('files only the supplies whose reporting date falls in the period', function () {
    $assessments = [
        $this->tax->assess(periodSupply('100.00', '2026-09-30')),  // Q3
        $this->tax->assess(periodSupply('200.00', '2026-10-01')),  // Q4
        $this->tax->assess(periodSupply('400.00', '2026-12-31')),  // Q4, on the last day
    ];

    $q4 = $this->returns->aggregate($assessments, ReturnPeriod::quarter(2026, 4));
    $line = $q4->lineFor(new CountryCode('DK'), 'DKK');

    // 200 + 400, not 700. The inclusive end date is the point — a quarter that ends
    // on 31 December has to contain the supplies made on 31 December.
    expect((string) $line?->net->getAmount())->toBe('600.00')
        ->and($line?->count)->toBe(2)
        ->and($q4->period?->describe())->toBe('Q4 2026');
});

it('follows the reporting date, not the tax point, when they differ', function () {
    // Supplied 30 December, reported in January. It is rated at December's rate and
    // filed in Q1 — one date cannot do both jobs.
    $straddling = $this->tax->assess(periodSupply('100.00', '2026-12-30', '2027-01-03'));

    $q4 = $this->returns->aggregate([$straddling], ReturnPeriod::quarter(2026, 4));
    $q1 = $this->returns->aggregate([$straddling], ReturnPeriod::quarter(2027, 1));

    expect($q4->lines)->toBe([])
        ->and((string) $q1->lineFor(new CountryCode('DK'), 'DKK')?->net->getAmount())->toBe('100.00')
        // ...and the tax point still drove the rate.
        ->and($straddling->taxPoint?->format('Y-m-d'))->toBe('2026-12-30')
        ->and($straddling->reportedOn?->format('Y-m-d'))->toBe('2027-01-03');
});

it('reports on the tax point when no separate date is given', function () {
    $assessment = $this->tax->assess(periodSupply('100.00', '2026-11-15'));

    expect($assessment->reportedOn?->format('Y-m-d'))->toBe('2026-11-15');
});

it('aggregates everything when no period is asked for', function () {
    // The previous behaviour, preserved: a caller that wants a running total still
    // gets one.
    $all = $this->returns->aggregate([
        $this->tax->assess(periodSupply('100.00', '2020-01-01')),
        $this->tax->assess(periodSupply('100.00', '2026-01-01')),
    ]);

    expect((string) $all->lineFor(new CountryCode('DK'), 'DKK')?->net->getAmount())->toBe('200.00')
        ->and($all->period)->toBeNull();
});

it('excludes an assessment that cannot say which period it belongs to', function () {
    // A hand-built assessment with no reporting date. Assuming it into the period
    // being filed would put an unknown supply on a return someone signs.
    $undated = new TaxAssessment(
        treatment: TaxTreatment::Standard,
        net: Money::of('100.00', 'DKK'),
        tax: Money::of('25.00', 'DKK'),
        gross: Money::of('125.00', 'DKK'),
        placeOfSupply: $this->geo->find(new CountryCode('DK')),
        rate: null,
        reason: 'no reporting date',
    );

    expect($this->returns->aggregate([$undated], ReturnPeriod::quarter(2026, 4))->lines)->toBe([]);
});

// ---- The period value object ---------------------------------------------

it('builds the windows an authority actually files on', function () {
    expect(ReturnPeriod::quarter(2026, 4)->from->format('Y-m-d'))->toBe('2026-10-01')
        ->and(ReturnPeriod::quarter(2026, 4)->to->format('Y-m-d'))->toBe('2026-12-31')
        ->and(ReturnPeriod::month(2026, 2)->to->format('Y-m-d'))->toBe('2026-02-28')
        ->and(ReturnPeriod::month(2028, 2)->to->format('Y-m-d'))->toBe('2028-02-29')  // leap
        ->and(ReturnPeriod::year(2026)->describe())->toBe('2026');
});

it('includes both bounds', function () {
    $q1 = ReturnPeriod::quarter(2026, 1);

    expect($q1->covers(new DateTimeImmutable('2026-01-01')))->toBeTrue()
        ->and($q1->covers(new DateTimeImmutable('2026-03-31')))->toBeTrue()
        ->and($q1->covers(new DateTimeImmutable('2026-04-01')))->toBeFalse();
});
