<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Returns\DefaultReturnAggregator;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\ReturnPeriod;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

// A stacked state is not remitted as one number. In Kansas the state takes 6.5%
// and a city such as authority 36000 adds 1.625%, each paid separately to a
// different authority. The aggregator reported "US-KS: $81.25" and stopped there,
// so the split — which the engine had already computed, per supply — had to be
// rebuilt by hand from the individual assessments. That is the one piece of
// arithmetic on a signed return that should never be done twice.

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->dataset = new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/us-tax-dataset',
    );
    $this->returns = new DefaultReturnAggregator;
    $this->calculator = new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(new StaticProductTaxability, $this->geo),
        new UsTaxDatasetRateSource($this->dataset),
    );
});

function ksAuthority(string $authority = '36000'): Jurisdiction
{
    return test()->geo->find(new CountryCode('US'), new SubdivisionCode('US-KS'))
        ->withLocality(new LocalityCode(new SubdivisionCode('US-KS'), 'sst-fips', $authority));
}

function ksReturnSupply(string $amount, string $reportedOn, string $authority = '36000'): TaxQuery
{
    return new TaxQuery(
        amount: Money::of($amount, 'USD'),
        pricing: Pricing::Exclusive,
        place: ksAuthority($authority),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-KS')),
        ]),
        reportedOn: new DateTimeImmutable($reportedOn),
    );
}

it('rolls a period up per authority, not just per jurisdiction', function () {
    $return = $this->returns->aggregate([
        $this->calculator->assess(ksReturnSupply('1000.00', '2026-08-03')),
        $this->calculator->assess(ksReturnSupply('1000.00', '2026-08-20')),
    ], ReturnPeriod::month(2026, 8));

    $line = $return->lineFor(new CountryCode('US'), 'USD', new SubdivisionCode('US-KS'));
    $authorities = $line?->authorities;

    expect($authorities)->not->toBeNull()->toHaveCount(2);

    $byLevel = [];

    foreach ($authorities as $authority) {
        $byLevel[$authority->level->value] = $authority;
    }

    // $2,000 of net across the month: state 6.5% = $130, city 1.625% = $32.50.
    expect((string) $byLevel['state']->tax->getAmount())->toBe('130.00')
        ->and((string) $byLevel['city']->tax->getAmount())->toBe('32.50')
        // ...and they still reconcile with the jurisdiction total the line reports.
        ->and((string) $line->tax->getAmount())->toBe('162.50');
});

it('keeps two different local authorities apart', function () {
    // County 209 (1%) and city 36000 (1.625%) are separate authorities and separate
    // cheques. Merging them by level alone would report one authority owed both.
    $return = $this->returns->aggregate([
        $this->calculator->assess(ksReturnSupply('1000.00', '2026-08-03', authority: '36000')),
        $this->calculator->assess(ksReturnSupply('1000.00', '2026-08-04', authority: '209')),
    ], ReturnPeriod::month(2026, 8));

    $codes = array_map(
        static fn ($a): ?string => $a->code,
        $return->lineFor(new CountryCode('US'), 'USD', new SubdivisionCode('US-KS'))?->authorities ?? [],
    );

    expect($codes)->toContain('36000')->toContain('209');
});

it('refuses the split when a taxed supply arrived without a breakdown', function () {
    // A partial roll-up is worse than none: what remains still adds up to a
    // plausible return, so the omission is invisible. Someone signs this.
    $hand = new TaxAssessment(
        treatment: TaxTreatment::Standard,
        net: Money::of('100.00', 'USD'),
        tax: Money::of('9.13', 'USD'),
        gross: Money::of('109.13', 'USD'),
        placeOfSupply: ksAuthority(),
        rate: null,
        reason: 'no breakdown',
        reportedOn: new DateTimeImmutable('2026-08-10'),
    );

    $return = $this->returns->aggregate([
        $this->calculator->assess(ksReturnSupply('1000.00', '2026-08-03')),
        $hand,
    ], ReturnPeriod::month(2026, 8));

    $line = $return->lineFor(new CountryCode('US'), 'USD', new SubdivisionCode('US-KS'));

    // The jurisdiction totals are still perfectly good — $81.25 from the assessed
    // supply plus the hand-built $9.13 — and only the split is unknown.
    expect($line?->authorities)->toBeNull()
        ->and((string) $line?->tax->getAmount())->toBe('90.38');
});

it('ignores an untaxed supply rather than refusing over it', function () {
    // A zero-tax supply has nothing to attribute and no missing breakdown to
    // complain about. Treating it as a gap would make exempt sales poison the
    // split for the whole period.
    $exempt = new TaxAssessment(
        treatment: TaxTreatment::Exempt,
        net: Money::of('500.00', 'USD'),
        tax: Money::zero('USD'),
        gross: Money::of('500.00', 'USD'),
        placeOfSupply: ksAuthority(),
        rate: null,
        reason: 'exempt',
        reportedOn: new DateTimeImmutable('2026-08-11'),
    );

    $return = $this->returns->aggregate([
        $this->calculator->assess(ksReturnSupply('1000.00', '2026-08-03')),
        $exempt,
    ], ReturnPeriod::month(2026, 8));

    expect($return->lineFor(new CountryCode('US'), 'USD', new SubdivisionCode('US-KS'))?->authorities)->not->toBeNull();
});

it('reports no split for a jurisdiction that never had one', function () {
    // A national VAT supply has one authority and no stack. There is nothing to
    // decompose, and inventing a single-entry "split" would suggest the engine
    // knew something it does not.
    $dk = new TaxQuery(
        amount: Money::of('100.00', 'DKK'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
        reportedOn: new DateTimeImmutable('2026-08-05'),
    );

    $return = $this->returns->aggregate(
        [$this->app->make(TaxCalculator::class)->assess($dk)],
        ReturnPeriod::month(2026, 8),
    );

    expect($return->lineFor(new CountryCode('DK'), 'DKK')?->authorities)->toBeNull();
});

it('does not merge authorities across different states', function () {
    // Each state files its own return, so the lines are already separate — but the
    // authority codes are only unique WITHIN a state, and a roll-up spanning lines
    // would collide them.
    $tx = new TaxQuery(
        amount: Money::of('1000.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('US'), new SubdivisionCode('US-TX'))
            ->withLocality(new LocalityCode(new SubdivisionCode('US-TX'), 'sst-fips', '2109064')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-TX')),
        ]),
        reportedOn: new DateTimeImmutable('2026-08-06'),
    );

    $return = $this->returns->aggregate([
        $this->calculator->assess(ksReturnSupply('1000.00', '2026-08-03')),
        $this->calculator->assess($tx),
    ], ReturnPeriod::month(2026, 8));

    expect($return->lines)->toHaveCount(2);

    foreach ($return->lines as $line) {
        foreach ($line->authorities ?? [] as $authority) {
            // Every state share must name its own state, never the other one.
            if ($authority->level === JurisdictionLevel::State) {
                expect($authority->code)->toBe($line->subdivision?->value);
            }
        }
    }
});
