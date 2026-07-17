<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\NexusCombinator;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Nexus\StaticNexusThresholds;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->thresholds = new StaticNexusThresholds;
});

it('exposes the published dollar threshold per state', function (string $state, int $dollars, ?int $transactions, NexusCombinator $combinator) {
    $t = $this->thresholds->for(new SubdivisionCode($state));

    expect($t)->not->toBeNull()
        ->and($t->salesDollars)->toBe($dollars)
        ->and($t->transactions)->toBe($transactions)
        ->and($t->combinator)->toBe($combinator);
})->with([
    'California (sales only)' => ['US-CA', 500_000, null, NexusCombinator::SalesOnly],
    'Texas (sales only)' => ['US-TX', 500_000, null, NexusCombinator::SalesOnly],
    'New York (both)' => ['US-NY', 500_000, 100, NexusCombinator::SalesAndTransactions],
    'Alabama ($250k)' => ['US-AL', 250_000, null, NexusCombinator::SalesOnly],
    'Connecticut (both)' => ['US-CT', 100_000, 200, NexusCombinator::SalesAndTransactions],
    'New Jersey (either)' => ['US-NJ', 100_000, 200, NexusCombinator::SalesOrTransactions],
    'Ohio ($100k)' => ['US-OH', 100_000, null, NexusCombinator::SalesOnly],
]);

it('returns null for a state with no general sales tax', function (string $state) {
    expect($this->thresholds->for(new SubdivisionCode($state)))->toBeNull();
})->with(['US-DE', 'US-MT', 'US-NH', 'US-OR']);

it('evaluates "either" thresholds on sales OR transactions', function () {
    $nj = $this->thresholds->for(new SubdivisionCode('US-NJ'));

    expect($nj->isMet(150_000, 10))->toBeTrue()   // sales alone
        ->and($nj->isMet(5_000, 250))->toBeTrue() // transactions alone
        ->and($nj->isMet(5_000, 10))->toBeFalse();
});

it('evaluates "both" thresholds on sales AND transactions', function () {
    $ct = $this->thresholds->for(new SubdivisionCode('US-CT'));

    expect($ct->isMet(150_000, 250))->toBeTrue()    // both met
        ->and($ct->isMet(150_000, 10))->toBeFalse() // sales only, not enough transactions
        ->and($ct->isMet(5_000, 250))->toBeFalse();
});

it('describes a threshold for display', function () {
    expect($this->thresholds->for(new SubdivisionCode('US-CA'))->describe())->toBe('$500,000')
        ->and($this->thresholds->for(new SubdivisionCode('US-NJ'))->describe())->toBe('$100,000 or 200 transactions')
        ->and($this->thresholds->for(new SubdivisionCode('US-CT'))->describe())->toBe('$100,000 and 200 transactions');
});

it('is bound as the default NexusThresholds contract', function () {
    expect($this->app->make(NexusThresholds::class))->toBeInstanceOf(StaticNexusThresholds::class);
});

it('flags the economic-nexus threshold on a not-registered US assessment', function () {
    /** @var TaxCalculator $tax */
    $tax = $this->app->make(TaxCalculator::class);

    // Seller registered in NY, selling to a CA buyer where it has no registration.
    $assessment = $tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-CA')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-NY')),
        ]),
    ));

    expect($assessment->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($assessment->reason)->toContain('Economic-nexus threshold there is $500,000');
});
