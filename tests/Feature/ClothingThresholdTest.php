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
use Cbox\Tax\Exceptions\ThresholdCurrencyMismatch;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\Taxability\UsTaxDatasetTaxability;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

// Three states exempt clothing below a per-item price, and they do NOT work alike.
// Massachusetts and Rhode Island tax only the amount OVER the threshold; New York
// taxes the whole item once it reaches $110, the first $110 included. Published as
// a bare figure the two are the same field with opposite meaning.
//
// Until the seam could see the line amount it had two options for these and both
// were wrong: charge every exempt garment, or refuse the line. It refused — which
// at an e-commerce checkout is a failed order over a rule we had the figures for.
//
// Rates here are the state rates only, so the arithmetic in each case is the
// threshold's, not the rate stack's.

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);

    $this->calculator = new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(
            new UsTaxDatasetTaxability(
                new UsTaxDataset(
                    $this->app->make(Factory::class),
                    $this->app->make(Cache::class),
                    dirname(__DIR__).'/Fixtures/us-tax-dataset',
                ),
                new StaticProductTaxability,
            ),
            $this->geo,
        ),
        new StaticTaxRateSource(['US-MA' => '6.25', 'US-NY' => '4', 'US-RI' => '7']),
    );
});

function garment(string $state, string $price, Pricing $pricing = Pricing::Exclusive): TaxQuery
{
    return new TaxQuery(
        amount: Money::of($price, 'USD'),
        pricing: $pricing,
        place: test()->geo->find(new CountryCode('US'), new SubdivisionCode($state)),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode($state)),
        ]),
        category: TaxClass::Clothing,
    );
}

// ---- Massachusetts: only the amount over $175 --------------------------------

it('taxes a Massachusetts garment on the amount over $175, not on the price', function () {
    // The state's own worked example: a $200 sweater is taxed on $25. At 6.25%
    // that is $1.56 — not the $12.50 a naive reading of "taxable" produces.
    $assessment = $this->calculator->assess(garment('US-MA', '200.00'));

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $assessment->tax->getAmount())->toBe('1.56')
        ->and((string) $assessment->net->getAmount())->toBe('200.00')
        ->and((string) $assessment->gross->getAmount())->toBe('201.56')
        ->and($assessment->reason)->toContain('above the exemption threshold');
});

it('exempts a Massachusetts garment below the threshold outright', function () {
    $assessment = $this->calculator->assess(garment('US-MA', '174.99'));

    expect($assessment->treatment)->toBe(TaxTreatment::Exempt)
        ->and((string) $assessment->tax->getAmount())->toBe('0.00')
        // The reason distinguishes a PRICE exemption from a category one. The next
        // garment at a higher price will be taxed, and a return should say why.
        ->and($assessment->reason)->toContain('below 175.00 USD per item');
});

// ---- New York: the whole item, once it reaches $110 --------------------------

it('taxes a New York garment on its ENTIRE price once it reaches $110', function () {
    // The opposite mechanic. Reading New York as Massachusetts would tax $90 of a
    // $200 dress instead of $200 — an under-collection on every garment over the
    // line in the state.
    $assessment = $this->calculator->assess(garment('US-NY', '200.00'));

    expect((string) $assessment->tax->getAmount())->toBe('8.00')   // 4% of 200, not of 90
        ->and($assessment->reason)->not->toContain('above the exemption threshold');
});

it('puts the New York cliff between $109.99 and $110.00', function () {
    // New York is explicit that a cent decides this. An inclusive comparison here
    // moves a whole band of ordinary retail prices to the wrong side.
    expect($this->calculator->assess(garment('US-NY', '109.99'))->treatment)->toBe(TaxTreatment::Exempt)
        ->and($this->calculator->assess(garment('US-NY', '110.00'))->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $this->calculator->assess(garment('US-NY', '110.00'))->tax->getAmount())->toBe('4.40');
});

// ---- Rhode Island: excess-only, like Massachusetts ---------------------------

it('taxes a Rhode Island garment on the amount over $250', function () {
    // A $300 coat is taxed on $50, at 7% — $3.50.
    expect((string) $this->calculator->assess(garment('US-RI', '300.00'))->tax->getAmount())->toBe('3.50');
});

it('exempts a Rhode Island garment at exactly the threshold minus a cent', function () {
    expect($this->calculator->assess(garment('US-RI', '249.99'))->treatment)->toBe(TaxTreatment::Exempt);
});

// ---- The invariants hold either way ------------------------------------------

it('keeps gross as net plus tax when only part of the line is taxed', function () {
    // The partial base changes what tax is computed ON, not what the customer is
    // billed. Several things in the engine depend on this invariant.
    $assessment = $this->calculator->assess(garment('US-MA', '200.00'));

    expect($assessment->net->plus($assessment->tax)->isEqualTo($assessment->gross))->toBeTrue();
});

it('handles a tax-inclusive price with a partial base', function () {
    // The exempt slice passes through untouched, so removing tax from the whole
    // gross would strip tax that was never added to it. Round-tripped: charging
    // 6.25% on the excess of $200 gives $201.56, so a tax-inclusive $201.56 must
    // give back the same $1.56.
    $assessment = $this->calculator->assess(garment('US-MA', '201.56', Pricing::Inclusive));

    expect((string) $assessment->tax->getAmount())->toBe('1.56')
        ->and((string) $assessment->net->getAmount())->toBe('200.00')
        ->and((string) $assessment->gross->getAmount())->toBe('201.56');
});

it('does not disturb a state that taxes clothing outright', function () {
    // California has no threshold: the whole price is taxed, as it always was.
    $calculator = new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(
            new UsTaxDatasetTaxability(
                new UsTaxDataset(
                    $this->app->make(Factory::class),
                    $this->app->make(Cache::class),
                    dirname(__DIR__).'/Fixtures/us-tax-dataset',
                ),
                new StaticProductTaxability,
            ),
            $this->geo,
        ),
        new StaticTaxRateSource(['US-CA' => '7.25']),
    );

    expect((string) $calculator->assess(garment('US-CA', '200.00'))->tax->getAmount())->toBe('14.50');
});

// ---- A credit note is a negative supply of the same garment -------------------

it('refunds exactly the tax the sale charged, on a threshold garment', function () {
    // The threshold compared the SIGNED amount, and -$200 is arithmetically "less
    // than $175" while being nothing of the sort. Read that way a refund returned
    // the price and kept the tax: the seller held money the customer was owed and
    // the state would want reconciled, and nothing about the figures looked wrong.
    $charged = $this->calculator->assess(garment('US-MA', '200.00'));
    $refunded = $this->calculator->assess(garment('US-MA', '-200.00'));

    expect((string) $charged->tax->getAmount())->toBe('1.56')
        ->and((string) $refunded->tax->getAmount())->toBe('-1.56')
        ->and($refunded->treatment)->toBe(TaxTreatment::Standard);

    // The pair nets to nothing, which is the whole point of a credit note.
    expect($charged->tax->plus($refunded->tax)->isZero())->toBeTrue();
});

it('refunds the whole tax on a New York cliff garment', function () {
    $charged = $this->calculator->assess(garment('US-NY', '200.00'));
    $refunded = $this->calculator->assess(garment('US-NY', '-200.00'));

    expect((string) $refunded->tax->getAmount())->toBe('-8.00')
        ->and($charged->tax->plus($refunded->tax)->isZero())->toBeTrue();
});

it('still refunds nothing for a garment that was never taxed', function () {
    // Below the threshold in both directions: no tax was charged, so none is due
    // back. The magnitude comparison must not turn an exempt sale into a taxable
    // refund either.
    expect($this->calculator->assess(garment('US-MA', '-100.00'))->treatment)->toBe(TaxTreatment::Exempt)
        ->and((string) $this->calculator->assess(garment('US-MA', '-100.00'))->tax->getAmount())->toBe('0.00');
});

it('charges nothing on a zero-amount line', function () {
    // A free item is below every threshold and taxed nowhere.
    expect($this->calculator->assess(garment('US-MA', '0.00'))->treatment)->toBe(TaxTreatment::Exempt);
});

// ---- The threshold is a number AND a currency ---------------------------------

it('refuses a threshold garment priced in a currency the statute does not name', function () {
    // New York's exemption is $110. The threshold travelled as minor units alone,
    // so `11000` meant whatever the invoice happened to count in: ¥11,000 against
    // a yen line — about seventy dollars, so garments New York taxes came out
    // exempt — and 11 dinar against a Bahraini one, where three decimal places
    // move the line by a factor of ten in the other direction.
    //
    // Converting is not this package's call to make. It would need a rate on the
    // supply date, and the rate it picked would decide whether the line is taxed
    // at all. Refusing hands that back to the host, which knows which rate its
    // own accounting uses.
    $yen = fn (): mixed => test()->calculator->assess(new TaxQuery(
        amount: Money::of('20000', 'JPY'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('US'), new SubdivisionCode('US-NY')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-NY')),
        ]),
        category: TaxClass::Clothing,
    ));

    expect($yen)->toThrow(ThresholdCurrencyMismatch::class, 'stated in USD but the amount is in JPY');
});

it('leaves a category with no price threshold free to be billed in any currency', function () {
    // The refusal is scoped to the thing that actually depends on the currency. A
    // category whose taxability does not turn on price has no threshold to compare
    // against, and blocking those would make a US assessment USD-only for no
    // reason at all.
    $assessment = test()->calculator->assess(new TaxQuery(
        amount: Money::of('20000', 'JPY'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('US'), new SubdivisionCode('US-NY')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-NY')),
        ]),
        category: TaxClass::GeneralGoods,
    ));

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and($assessment->tax->getCurrency()->getCurrencyCode())->toBe('JPY');
});
