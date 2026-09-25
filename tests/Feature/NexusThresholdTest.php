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
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterNexus;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

beforeEach(function (): void {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->thresholds = app(NexusThresholds::class);
});

it('exposes the published dollar threshold per state', function (string $state, int $dollars, ?int $transactions, NexusCombinator $combinator): void {
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

it('returns null for a state with no general sales tax', function (string $state): void {
    expect($this->thresholds->for(new SubdivisionCode($state)))->toBeNull();
})->with(['US-DE', 'US-MT', 'US-NH', 'US-OR']);

it('carries the figures but refuses to reach a verdict', function (): void {
    // This object used to answer isMet($sales, $transactions): bool. It could not
    // legitimately: the answer turns on the state's measuring PERIOD and sales
    // BASIS, which it does not carry — so the same seller totals returned a
    // confident `true` here and `Unknown` from the package that models both.
    // Two packages, one question, contradictory answers. The verdict belongs to
    // whichever one knows the period and the basis, and that is not this one.
    $nj = $this->thresholds->for(new SubdivisionCode('US-NJ'));

    expect(method_exists($nj, 'isMet'))->toBeFalse()
        ->and($nj->salesDollars)->toBe(100_000)
        ->and($nj->transactions)->toBe(200);
});

it('describes a threshold for display', function (): void {
    expect($this->thresholds->for(new SubdivisionCode('US-CA'))->describe())->toBe('$500,000')
        ->and($this->thresholds->for(new SubdivisionCode('US-NJ'))->describe())->toBe('$100,000 or 200 transactions')
        ->and($this->thresholds->for(new SubdivisionCode('US-CT'))->describe())->toBe('$100,000 and 200 transactions');
});

it('is bound to the register-backed NexusThresholds', function (): void {
    // There is no longer a static table behind it to fall back to: the register is
    // the source, and a state it holds no threshold for answers null rather than a
    // figure somebody typed.
    expect($this->app->make(NexusThresholds::class))->toBeInstanceOf(RegisterNexus::class)
        ->and($this->thresholds->for(new SubdivisionCode('US-MT')))->toBeNull();
});

it('flags the economic-nexus threshold on a not-registered US assessment', function (): void {
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

it('carries what the state counts and when collection starts, rather than refusing the threshold', function (): void {
    // Twelve states publish these beside the figure — Arizona, California, Colorado,
    // Oklahoma and eight more. Refusing them left every one of those states with no
    // nexus answer at all, which is worse than an answer a host has to qualify.
    $az = $this->thresholds->for(new SubdivisionCode('US-AZ'));

    expect($az?->salesDollars)->toBe(100_000)
        ->and(array_map(fn ($m): array => [$m->dimension, $m->treatment], $az->measuredBy))
        ->toBe([['marketplace_sales', 'excluded'], ['affiliated_persons', 'aggregate']])
        ->and($az->obligations[0]->action)->toBe('remit')
        ->and($az->obligations[0]->dateKind)->toBe('first_month_start_on_or_after_days')
        ->and($az->obligations[0]->dateFigure)->toBe(30)
        ->and($az->obligations[0]->says)->toContain('thirty days');
});

it('still refuses a threshold qualified by something it does not model', function (): void {
    // `unresolvedQualifications` is the register saying it has not modelled the
    // statutory trigger. There is nothing to report and nothing to apply.
    $root = config('tax.register.store').'/unresolved-threshold';

    FakeRegister::at($root)
        ->rate('us:KS', '6.5', from: '1990-01-01')
        ->rule('us:KS', 'threshold', [
            'amount' => '100000.00', 'currency' => 'USD', 'binds' => 'remote_seller',
            'unresolvedQualifications' => [['says' => 'or otherwise has a physical presence']],
        ])
        ->install();

    $layout = new StoreLayout($root);
    $nexus = new RegisterNexus(new RegisterDataset($layout, new StorePointer($layout)));

    expect(fn (): ?\Cbox\Tax\ValueObjects\NexusThreshold => $nexus->for(new SubdivisionCode('US-KS')))
        ->toThrow(UnresolvedTaxRule::class, 'unresolvedQualifications');
});
