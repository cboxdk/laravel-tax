<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Catalogue\ArrayProductCatalogue;
use Cbox\Tax\Contracts\ProductCatalogue;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\EuTaxData\EuTaxDataset;
use Cbox\Tax\RateSource\EuTaxDatasetRateSource;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\ValueObjects\ProductTaxMapping;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

/** The shipped calculator with a catalogue bound over the empty default. */
function calculatorWithCatalogue(ProductCatalogue $catalogue): TaxCalculator
{
    // app(), not test()->app — the property is protected outside a bound closure,
    // and Testbench resolves the same container either way.
    app()->instance(ProductCatalogue::class, $catalogue);

    // The base TestCase points at the US fixture only, so the EU source is not in
    // the chain by default and Hungary would fall through to the static snapshot.
    config()->set('tax.eu_tax_data.location', dirname(__DIR__).'/Fixtures/eu-tax-dataset');
    app()->forgetInstance(TaxRateSource::class);

    return app(TaxCalculator::class);
}

/** A domestic B2C supply carrying an item code. */
function catalogueQuery(?string $itemCode, string $country = 'DK', ?TaxClass $class = null): TaxQuery
{
    return new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode($country)),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode($country)),
        category: $class ?? TaxClass::GeneralGoods,
        itemCode: $itemCode,
    );
}

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
});

// ---------------------------------------------------------------------------
// Step one: find the class from the words a merchant actually uses
// ---------------------------------------------------------------------------

it('finds a class from the merchant\'s own word, not ours', function (string $typed, TaxClass $expected) {
    expect(TaxClass::search($typed)[0] ?? null)->toBe($expected);
})->with([
    'the example, not the class name' => ['trainers', TaxClass::Footwear],
    'singular against a plural example' => ['shoe', TaxClass::Footwear],
    'the class name itself' => ['clothing', TaxClass::Clothing],
    'a partial name' => ['furni', TaxClass::Furniture],
    'shouting' => ['LAPTOPS', TaxClass::Electronics],
]);

it('returns nothing for a product no class expresses', function () {
    // The important answer. Nothing here covers school supplies, and a merchant
    // must learn that at MAPPING time, where it can be recorded — not by being
    // charged the standard rate through a holiday that exempted them.
    expect(TaxClass::search('notebooks'))->toBe([])
        ->and(TaxClass::search('pencils'))->toBe([]);
});

it('ignores an empty search rather than returning everything', function () {
    expect(TaxClass::search('   '))->toBe([]);
});

it('orders stably, so a picker does not reshuffle between requests', function () {
    expect(TaxClass::search('software'))->toBe(TaxClass::search('software'));
});

it('carries what a picker needs to render a row', function () {
    $info = TaxClass::Footwear->info();

    expect($info->name)->toBe('Footwear')
        ->and($info->examples)->toContain('trainers')
        ->and($info->cnPrefixes)->toContain('64');
});

// ---------------------------------------------------------------------------
// Step two: the assessment says what limited it, and what would fix it
// ---------------------------------------------------------------------------

it('names the gap AND the remedy when a heading is ambiguous', function () {
    $source = new EuTaxDatasetRateSource(new EuTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/eu-tax-dataset',
    ));

    $rate = $source->rateFor($this->geo->find(new CountryCode('HU')), TaxClass::Groceries);

    expect($rate?->limitedBy)->toBe(RateLimit::HeadingAmbiguous)
        ->and($rate?->limitedBy?->remedy())->toContain('commodityCode')
        // The distinction a review screen sorts on: this one the caller closes
        // themselves by classifying the product.
        ->and($rate?->limitedBy?->callerCanClose())->toBeTrue();
});

it('drops the limit once the code resolves it', function () {
    $source = new EuTaxDatasetRateSource(new EuTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/eu-tax-dataset',
    ));

    $rate = $source->rateForCommodity(
        $this->geo->find(new CountryCode('HU')),
        TaxClass::Groceries,
        'cn:01022110',
    );

    // Nothing to report: the answer is exact for what was asked, so there is no
    // object to allocate and nothing for a caller to check.
    expect($rate?->limitedBy)->toBeNull();
});

it('names the local gap where the address stopped at the state line', function () {
    $source = new UsTaxDatasetRateSource(new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/us-tax-dataset',
    ));

    $rate = $source->rateFor(
        $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-TX')),
        TaxClass::GeneralGoods,
    );

    expect($rate?->limitedBy)->toBe(RateLimit::NoLocalResolution)
        ->and($rate?->limitedBy?->remedy())->toContain('rooftop')
        // NOT the caller's to close by classifying anything — it is the operator's,
        // by configuration. Sorting a review by this is what lets one decision fix
        // a hundred products instead of investigating each.
        ->and($rate?->limitedBy?->callerCanClose())->toBeFalse();
});

it('reports no limit in a state with no local tax to miss', function () {
    $source = new UsTaxDatasetRateSource(new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/us-tax-dataset',
    ));

    // The state rate IS the whole rate in a state whose locals levy nothing, so
    // flagging it would train the operator to ignore the flag.
    $rate = $source->rateFor(
        $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-CT')),
        TaxClass::GeneralGoods,
    );

    expect($rate?->limitedBy)->toBeNull();
});

it('gives every limit a remedy, so none is a dead end', function () {
    foreach (RateLimit::cases() as $limit) {
        expect($limit->remedy())->not->toBe('');
    }
});

// ---------------------------------------------------------------------------
// Step three: the mapping lives on the product, not on the invoice line
// ---------------------------------------------------------------------------

it('resolves the class from the item code, so the line never decides', function () {
    // The shape both commercial engines settled on: register the mapping once
    // against your SKU, then send the SKU. Avalara takes an itemCode and resolves
    // the tax code server-side; Stripe hangs the code on the Product object.
    $calculator = calculatorWithCatalogue(new ArrayProductCatalogue([
        'SHOE-001' => TaxClass::Footwear,
    ]));

    $assessment = $calculator->assess(catalogueQuery('SHOE-001'));

    expect($assessment->rate?->limitedBy)->toBeNull();
});

it('carries the product\'s commodity code too, so the exact rate is reached', function () {
    // The code is a fact about the product, established once — not a decision
    // remade per order. Hungary's foodstuffs heading is 5% and 18% at once; the
    // mapping settles it without the invoice line knowing anything about CN.
    $calculator = calculatorWithCatalogue(new ArrayProductCatalogue([
        'MILK-1L' => new ProductTaxMapping(TaxClass::Groceries, 'cn:01022110'),
    ]));

    $assessment = $calculator->assess(catalogueQuery('MILK-1L', 'HU'));

    expect((string) $assessment->rate?->percentage)->toBe('5')
        ->and($assessment->rate?->limitedBy)->toBeNull();
});

it('flags a SKU nothing has mapped instead of taxing it in silence', function () {
    // The gap both competitors leave. An unmapped SKU still produces an invoice —
    // at the fallback class — and nothing says it did. This is the finding that
    // lets a review list every product nobody has classified.
    $calculator = calculatorWithCatalogue(new ArrayProductCatalogue);

    $assessment = $calculator->assess(catalogueQuery('NEVER-MAPPED'));

    expect($assessment->rate?->limitedBy)->toBe(RateLimit::ItemUnmapped)
        ->and($assessment->rate?->limitedBy?->callerCanClose())->toBeTrue()
        ->and($assessment->rate?->limitedBy?->remedy())->toContain('ProductCatalogue');
});

it('lets an explicit class on the line override the catalogue', function () {
    // Most specific wins. A caller who states a class for the line in hand has
    // overridden the product's general mapping deliberately, and the catalogue is
    // not consulted at all.
    $calculator = calculatorWithCatalogue(new ArrayProductCatalogue([
        'SHOE-001' => TaxClass::Footwear,
    ]));

    $assessment = $calculator->assess(catalogueQuery('SHOE-001', 'DK', TaxClass::Book));

    expect($assessment->rate?->limitedBy)->toBeNull();
});

it('behaves exactly as before for a caller that sends no item code', function () {
    $calculator = calculatorWithCatalogue(new ArrayProductCatalogue);

    expect($calculator->assess(catalogueQuery(null))->rate?->limitedBy)->toBeNull();
});
