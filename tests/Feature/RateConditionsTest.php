<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Catalogue\ArrayProductCatalogue;
use Cbox\Tax\Catalogue\CatalogueAudit;
use Cbox\Tax\Contracts\ProductCatalogue;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\RateSource\CachingTaxRateSource;
use Cbox\Tax\Register\Reader\Predicate;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\DecisionFacts;
use Cbox\Tax\ValueObjects\ProductTaxMapping;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;
use Cbox\Tax\ValueObjects\TaxRate;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/*
 * A category finds the neighbourhood; the conditions decide the house.
 *
 * The United Kingdom zero-rates agricultural inputs — seeds for food crops and live
 * animals of a kind used for food — and names fertiliser nowhere. A seller who files
 * fertiliser under agricultural inputs has classified it correctly and is owed 20%.
 * The engine used to return 0%, authoritative, because it had been asked about
 * exactly that category and read conditions only when climbing to a broader one.
 */

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/rate-conditions');

    FakeRegister::at(config('tax.register.store'))
        ->category('goods')->category('services')
        ->category('goods.agricultural_inputs', 'goods')
        ->category('goods.plants', 'goods')
        ->category('goods.animals', 'goods')
        ->category('goods.food', 'goods')
        ->rate('europe:GB', '20', from: '1990-01-01')
        // Written in tariff codes: a commodity code settles it both ways.
        ->rate('europe:GB', '0', 'zero', 'goods.agricultural_inputs', from: '1990-01-01', extra: ['conditions' => [[
            'kind' => 'applies_only_to',
            'says' => 'Live animals of a kind generally used as, or yielding or producing, food for human consumption.',
            'predicate' => ['fact' => 'classification.cnCode', 'op' => 'prefix_in', 'value' => ['0102', '0103', '0104'], 'says' => 'bovine, swine, sheep and goats'],
        ]]])
        // Written as a product fact: only the fact settles it.
        ->rate('europe:GB', '0', 'zero', 'goods.plants', from: '1990-01-01', extra: ['conditions' => [[
            'kind' => 'applies_only_to',
            'says' => 'Seeds or other means of propagation of plants comprised in item 1 or 2.',
            'predicate' => ['fact' => 'product.intendedForSowing', 'op' => 'eq', 'value' => true, 'says' => 'Seeds…'],
        ]]])
        // Written as EITHER: a code can confirm it, but never refute it alone.
        ->rate('europe:GB', '0', 'zero', 'goods.animals', from: '1990-01-01', extra: ['conditions' => [[
            'kind' => 'applies_only_to',
            'says' => 'Live animals of a kind generally used as, or yielding or producing, food for human consumption.',
            'predicate' => ['any' => [
                ['fact' => 'product.isLiveAnimalOfAKindYieldingHumanFood', 'op' => 'eq', 'value' => true, 'says' => 'Live animals…'],
                ['fact' => 'classification.cnCode', 'op' => 'prefix_in', 'value' => ['0102', '0103', '0104'], 'says' => 'bovine, swine, sheep and goats'],
            ]],
        ]]])
        ->rate('europe:GB', '0', 'zero', 'goods.food', from: '1990-01-01', extra: ['conditions' => [[
            'kind' => 'excludes',
            'says' => 'Confectionery, not including cakes or biscuits other than biscuits wholly or partly covered with chocolate.',
            'predicate' => ['fact' => 'product.isConfectionery', 'op' => 'eq', 'value' => true, 'says' => 'Confectionery…'],
        ]]])
        ->install();
});

function gbRate(string $key, ?string $code = null, array $facts = []): ?TaxRate
{
    return (new RegisterRateSource(app(RegisterDataset::class)))
        ->withFacts(new DecisionFacts($facts))
        ->rateForKey(app(JurisdictionRepository::class)->find(new CountryCode('GB')), $key, $code);
}

it('flags a qualifying condition at the very category that was asked about', function (): void {
    $fertiliser = gbRate('goods.agricultural_inputs');

    expect((string) $fertiliser?->percentage)->toBe('0')
        ->and($fertiliser?->confidence)->toBe(Confidence::Derived)
        ->and($fertiliser?->limitedBy)->toBe(RateLimit::ConditionsUnevaluated);
});

it('settles it from the product\'s facts, either way', function (): void {
    $seed = gbRate('goods.plants', facts: ['product.intendedForSowing' => true]);
    $compost = gbRate('goods.plants', facts: ['product.intendedForSowing' => false]);

    expect((string) $seed?->percentage)->toBe('0')
        ->and($seed?->confidence)->toBe(Confidence::Authoritative)
        ->and($seed?->limitedBy)->toBeNull()
        // A false qualification removes the rate, and the ladder carries on to the
        // answer that does apply.
        ->and((string) $compost?->percentage)->toBe('20')
        ->and($compost?->confidence)->toBe(Confidence::Authoritative);
});

it('settles a condition written in tariff codes from the commodity code alone', function (): void {
    // No facts at all: a seller who classified the product for customs has already
    // answered every condition the register writes in codes.
    $cattle = gbRate('goods.agricultural_inputs', '0102 21 10');
    $fertiliser = gbRate('goods.agricultural_inputs', '3102 10 10');

    expect((string) $cattle?->percentage)->toBe('0')
        ->and($cattle?->limitedBy)->toBeNull()
        ->and((string) $fertiliser?->percentage)->toBe('20')
        ->and($fertiliser?->limitedBy)->toBeNull();
});

it('drops a rate whose exclusion the facts make true, at any rung', function (): void {
    $sweets = gbRate('goods.food', facts: ['product.isConfectionery' => true]);
    $bread = gbRate('goods.food', facts: ['product.isConfectionery' => false]);

    expect((string) $sweets?->percentage)->toBe('20')
        ->and((string) $bread?->percentage)->toBe('0')
        ->and($bread?->confidence)->toBe(Confidence::Authoritative);
});

it('lets a code confirm an either-or condition but never refute it alone', function (): void {
    // "Live animals used for food" published as EITHER a product fact OR a list of
    // tariff headings. A bovine code proves it; a fertiliser code proves only that
    // the code limb is false — the fact limb is still open, and three-valued `any`
    // cannot call that false. Only a condition written purely in codes is settled by
    // a code both ways, which is why the register should write them that way.
    $cattle = gbRate('goods.animals', '0102 21 10');
    $fertiliser = gbRate('goods.animals', '3102 10 10');

    expect((string) $cattle?->percentage)->toBe('0')
        ->and($cattle?->limitedBy)->toBeNull()
        ->and((string) $fertiliser?->percentage)->toBe('0')
        ->and($fertiliser?->limitedBy)->toBe(RateLimit::ConditionsUnevaluated);
});

it('leaves an unsettled exclusion at the exact rung unflagged, as before', function (): void {
    // Asked about food, "excluding confectionery" is usually not about you — the
    // Ireland books-but-not-newspapers case. Only a qualifying condition is flagged here.
    expect(gbRate('goods.food')?->limitedBy)->toBeNull();
});

it('reports what would settle an answer, for a catalogue to ask about', function (): void {
    $source = (new RegisterRateSource(app(RegisterDataset::class)));
    $gb = app(JurisdictionRepository::class)->find(new CountryCode('GB'));

    $open = $source->unsettledConditions($gb, 'goods.animals');
    $settled = $source->unsettledConditions($gb, 'goods.animals', '0102 21 10');

    expect($open)->toHaveCount(1)
        ->and($open[0]->kind)->toBe('applies_only_to')
        ->and($open[0]->facts)->toBe(['product.isLiveAnimalOfAKindYieldingHumanFood'])
        ->and($open[0]->settledByCommodityCode)->toBeTrue()
        ->and($open[0]->isSettleable())->toBeTrue()
        ->and($settled)->toBe([]);
});

it('carries a query\'s facts through the calculator', function (): void {
    $a = app(TaxCalculator::class)->assess(new TaxQuery(
        amount: Money::of('100.00', 'GBP'),
        pricing: Pricing::Exclusive,
        place: app(JurisdictionRepository::class)->find(new CountryCode('GB')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('GB')),
        categoryKey: 'goods.food',
        facts: new DecisionFacts(['product.isConfectionery' => true]),
    ));

    expect((string) $a->tax->getAmount())->toBe('20.00');
});

it('takes a product\'s facts, key and code from the catalogue, stated once', function (): void {
    app()->instance(ProductCatalogue::class, new ArrayProductCatalogue([
        // Classified once, for customs as much as for tax: the code settles it.
        'SKU-CATTLE' => new ProductTaxMapping(TaxClass::GeneralGoods, '0102 21 10', 'goods.agricultural_inputs'),
        'SKU-FERT' => new ProductTaxMapping(TaxClass::GeneralGoods, '3102 10 10', 'goods.agricultural_inputs'),
        // What a code cannot say, stated as a fact once.
        'SKU-SWEETS' => new ProductTaxMapping(TaxClass::GeneralGoods, null, 'goods.food', new DecisionFacts(['product.isConfectionery' => true])),
    ]));
    app()->forgetInstance(TaxCalculator::class);

    $sell = fn (string $sku): TaxQuery => new TaxQuery(
        amount: Money::of('100.00', 'GBP'),
        pricing: Pricing::Exclusive,
        place: app(JurisdictionRepository::class)->find(new CountryCode('GB')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('GB')),
        itemCode: $sku,
    );

    expect((string) app(TaxCalculator::class)->assess($sell('SKU-CATTLE'))->tax->getAmount())->toBe('0.00')
        ->and((string) app(TaxCalculator::class)->assess($sell('SKU-FERT'))->tax->getAmount())->toBe('20.00')
        ->and((string) app(TaxCalculator::class)->assess($sell('SKU-SWEETS'))->tax->getAmount())->toBe('20.00');
});

it('keys a cache on the facts, so two products in one category get two answers', function (): void {
    $cached = new CachingTaxRateSource(new RegisterRateSource(app(RegisterDataset::class)), new Repository(new ArrayStore));
    $gb = app(JurisdictionRepository::class)->find(new CountryCode('GB'));

    $sweets = $cached->withFacts(new DecisionFacts(['product.isConfectionery' => true]))->rateForKey($gb, 'goods.food');
    $bread = $cached->withFacts(new DecisionFacts(['product.isConfectionery' => false]))->rateForKey($gb, 'goods.food');

    expect((string) $sweets?->percentage)->toBe('20')
        ->and((string) $bread?->percentage)->toBe('0');
});

it('reads the whole predicate grammar, three-valued', function (array $predicate, array $facts, ?bool $expected): void {
    expect(Predicate::evaluate($predicate, new DecisionFacts($facts)))->toBe($expected);
})->with([
    'eq true' => [['fact' => 'product.isBread', 'op' => 'eq', 'value' => true], ['product.isBread' => true], true],
    'absent is unknown, not false' => [['fact' => 'product.isBread', 'op' => 'eq', 'value' => true], [], null],
    'in' => [['fact' => 'product.foodUse', 'op' => 'in', 'value' => ['preparation', 'supplement']], ['product.foodUse' => 'supplement'], true],
    'at_most' => [['fact' => 'consignment.intrinsicValueEur', 'op' => 'at_most', 'value' => '150'], ['consignment.intrinsicValueEur' => '150.00'], true],
    'exceeds' => [['fact' => 'consignment.intrinsicValueEur', 'op' => 'exceeds', 'value' => '150'], ['consignment.intrinsicValueEur' => '150.00'], false],
    'prefix_in' => [['fact' => 'classification.cnCode', 'op' => 'prefix_in', 'value' => ['0401']], ['classification.cnCode' => '04011010'], true],
    'any: one true decides' => [['any' => [['fact' => 'product.isBread', 'op' => 'eq', 'value' => true], ['fact' => 'product.isCake', 'op' => 'eq', 'value' => true]]], ['product.isCake' => true], true],
    'any: unknown without a true' => [['any' => [['fact' => 'product.isBread', 'op' => 'eq', 'value' => true], ['fact' => 'product.isCake', 'op' => 'eq', 'value' => true]]], ['product.isCake' => false], null],
    'all: one false decides' => [['all' => [['fact' => 'product.isBread', 'op' => 'eq', 'value' => true], ['fact' => 'product.isCake', 'op' => 'eq', 'value' => true]]], ['product.isBread' => false], false],
    'not' => [['not' => ['fact' => 'product.isBread', 'op' => 'eq', 'value' => true]], ['product.isBread' => false], true],
    'not unknown stays unknown' => [['not' => ['fact' => 'product.isBread', 'op' => 'eq', 'value' => true]], [], null],
    'unsettled never answers' => [['unsettled' => ['says' => 'the part the register could not read']], ['product.isBread' => true], null],
    'an operator it does not know is unknown' => [['fact' => 'product.isBread', 'op' => 'resembles', 'value' => true], ['product.isBread' => true], null],
]);

it('audits a catalogue against a market, and says what each product is missing', function (): void {
    app()->instance(ProductCatalogue::class, new ArrayProductCatalogue([
        'SKU-CATTLE' => new ProductTaxMapping(TaxClass::GeneralGoods, '0102 21 10', 'goods.agricultural_inputs'),
        'SKU-FEED' => new ProductTaxMapping(TaxClass::GeneralGoods, null, 'goods.agricultural_inputs'),
        'SKU-LAMB' => new ProductTaxMapping(TaxClass::GeneralGoods, null, 'goods.animals'),
    ]));

    $gb = app(JurisdictionRepository::class)->find(new CountryCode('GB'));
    $findings = app(CatalogueAudit::class)->audit(['SKU-CATTLE', 'SKU-FEED', 'SKU-LAMB', 'SKU-NOBODY-MAPPED'], [$gb]);
    $by = [];

    foreach ($findings as $finding) {
        $by[$finding->itemCode] = $finding;
    }

    // The coded product is settled and does not appear at all.
    expect(array_keys($by))->toBe(['SKU-FEED', 'SKU-LAMB', 'SKU-NOBODY-MAPPED'])
        ->and($by['SKU-FEED']->settleableByCommodityCode())->toBeTrue()
        ->and($by['SKU-FEED']->factsNeeded())->toBe([])
        ->and($by['SKU-LAMB']->factsNeeded())->toBe(['product.isLiveAnimalOfAKindYieldingHumanFood'])
        ->and($by['SKU-NOBODY-MAPPED']->isUnmapped())->toBeTrue();
});
