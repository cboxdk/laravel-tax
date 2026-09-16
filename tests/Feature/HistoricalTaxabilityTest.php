<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterTaxability;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Taxability\AlwaysTaxable;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

// Taxability is dated law, not a standing fact. States move categories in and out
// of tax on their own schedule, and the dataset already carried those rules as
// dated windows — but the seam had no date parameter, so every window was
// evaluated against TODAY. A reissued invoice for a supply made two years ago was
// priced with that year's RATE and this year's TAXABILITY.
//
// That is not a rounding difference. A state that started taxing a category last
// year would have the engine charge tax on a supply made before the law existed.
// And the answer still looks like a number, which is what makes it dangerous.

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
});

/** A dataset whose only taxability rule for groceries changed on 2026-01-01. */
function datedTaxabilityDataset(): RegisterDataset
{
    $root = sys_get_temp_dir().'/tax-dated-'.bin2hex(random_bytes(5));

    // Kansas exempted groceries until the end of 2025 and taxes them from 2026 — the
    // same state, the same category, two windows. Read as "the current answer" a
    // 2025 credit note prices against a law that had not arrived yet.
    FakeRegister::at($root)
        ->rate('us:KS', '6.5', from: '1990-01-01')
        ->rate('us:KS', '0', 'exempt', 'goods.food.basic', from: '1990-01-01', until: '2025-12-31')
        ->rate('us:KS', '6.5', 'standard', 'goods.food.basic', from: '2026-01-01')
        ->install();

    $layout = new StoreLayout($root);

    return new RegisterDataset($layout, new StorePointer($layout));
}

function datedGrocerySupply(string $suppliedAt): TaxQuery
{
    return new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('US'), new SubdivisionCode('US-KS')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-KS')),
        ]),
        category: TaxClass::Groceries,
        suppliedAt: new DateTimeImmutable($suppliedAt),
    );
}

function datedCalculator(): DefaultTaxCalculator
{
    return new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(
            new RegisterTaxability(datedTaxabilityDataset()),
            test()->geo,
        ),
        rateSourceFor(['US-KS' => '6.5']),
    );
}

it('reads the taxability rule in force on the SUPPLY date, not today', function () {
    $calculator = datedCalculator();

    $before = $calculator->assess(datedGrocerySupply('2025-06-15'));
    $after = $calculator->assess(datedGrocerySupply('2026-06-15'));

    // Same state, same category, same engine — different law.
    expect($before->treatment)->toBe(TaxTreatment::Exempt)
        ->and((string) $before->tax->getAmount())->toBe('0.00')
        ->and($after->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $after->tax->getAmount())->toBe('6.50');
});

it('reads the window boundaries inclusively, on both sides', function () {
    $calculator = datedCalculator();

    // 31 December is the last day of the exempt window; 1 January the first of the
    // taxed one. An off-by-one here is a whole day of invoices priced wrong.
    expect($calculator->assess(datedGrocerySupply('2025-12-31'))->treatment)->toBe(TaxTreatment::Exempt)
        ->and($calculator->assess(datedGrocerySupply('2026-01-01'))->treatment)->toBe(TaxTreatment::Standard);
});

it('uses the same date the rate was resolved against', function () {
    // The point of the fix: one date drives both, so an assessment cannot be
    // internally inconsistent — priced with one year's rate and another's law.
    $assessment = datedCalculator()->assess(datedGrocerySupply('2025-06-15'));

    expect($assessment->taxPoint?->format('Y-m-d'))->toBe('2025-06-15');
});

it('answers as of today when the supply carries no date', function () {
    // Previous behaviour preserved for the ordinary case: today's supply, today's
    // law. Today is after the change, so the category is taxed.
    expect(datedCalculator()->assess(new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-KS')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-KS')),
        ]),
        category: TaxClass::Groceries,
    ))->treatment)->toBe(TaxTreatment::Standard);
});

it('is honest that the static matrix has no dated windows to consult', function () {
    // It accepts the date and ignores it, because it is a hand-maintained snapshot
    // that only knows one answer. Stating that plainly beats pretending the
    // parameter does something.
    $static = new AlwaysTaxable(['US-KS:groceries' => true]);
    $place = $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-KS'));

    expect($static->determine($place, TaxClass::Groceries, anyAmount(), new DateTimeImmutable('1999-01-01'))->isExemptFor(anyAmount()))->toBeFalse()
        ->and($static->determine($place, TaxClass::Groceries, anyAmount(), new DateTimeImmutable('2099-01-01'))->isExemptFor(anyAmount()))->toBeFalse();
});
