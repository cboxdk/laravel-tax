<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\OssStatus;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

/*
 * Who collects. A tax is only the seller's to charge where the seller is registered
 * to charge it — or, inside the EU, where the Union's own schemes make it so. Outside
 * the United States the engine charged the destination's tax on every sale,
 * registered or not, so a Danish shop billed Norwegian VAT it had no number to
 * remit under, and a marketplace sale was taxed twice: once by the platform the law
 * makes liable, once by the seller.
 */

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/collection-gate');

    FakeRegister::at(config('tax.register.store'))
        ->rate('eu:DE', '19', from: '1990-01-01')
        ->rate('eu:DK', '25', from: '1990-01-01')
        ->rate('eu:FR', '20', from: '1990-01-01')
        ->rate('europe:NO', '25', from: '1990-01-01')
        ->rate('europe:GB', '20', from: '1990-01-01')
        ->rule('europe:GB', 'marketplace_facilitator', ['platformOwes' => true], '2021-01-01')
        // Article 14a as schema 2.6 types it: a mandate over SOME facilitated sales.
        ->rule('eu:FR', 'marketplace_facilitator', ['platformOwes' => true, 'conditions' => [[
            'kind' => 'applies_only_to',
            'says' => 'facilitates distance sales of goods imported from third territories or third countries in consignments of an intrinsic value not exceeding EUR 150',
            'names' => 'an imported consignment worth at most EUR 150',
            'predicate' => ['all' => [
                ['fact' => 'supply.isDistanceSaleOfGoods', 'op' => 'eq', 'value' => true, 'says' => 'distance sales of goods imported'],
                ['fact' => 'consignment.intrinsicValueEur', 'op' => 'at_most', 'value' => '150', 'says' => 'not exceeding EUR 150'],
            ]],
        ]]], '2021-07-01')
        ->install();
});

function gateQuery(string $seller, string $buyer, array $registeredIn = [], ?OssStatus $oss = null, CustomerType $customer = CustomerType::Consumer, bool $validated = false, bool $marketplace = false, TaxClass $class = TaxClass::GeneralGoods): TaxQuery
{
    return new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: app(JurisdictionRepository::class)->find(new CountryCode($buyer)),
        customer: $customer,
        seller: new SellerRegistrations(new CountryCode($seller), array_map(fn (string $cc) => new SellerRegistration(new CountryCode($cc)), $registeredIn), $oss),
        category: $class,
        customerTaxIdValidated: $validated,
        suppliedAt: new DateTimeImmutable('2026-09-22'),
        marketplaceFacilitated: $marketplace,
    );
}

it('does not charge a destination tax the seller is not registered to collect', function (): void {
    // A Danish shop selling a laptop to a Norwegian consumer, with no Norwegian
    // registration. The seller has no number to remit Norwegian VAT under; the buyer
    // pays it on import.
    $a = app(TaxCalculator::class)->assess(gateQuery('DK', 'NO'));

    expect($a->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and((string) $a->tax->getAmount())->toBe('0.00');
});

it('charges it once the seller is registered there', function (): void {
    $a = app(TaxCalculator::class)->assess(gateQuery('DK', 'NO', registeredIn: ['NO']));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('25.00');
});

it('treats a non-EU seller without an EU scheme as not registered in the Union', function (): void {
    expect(app(TaxCalculator::class)->assess(gateQuery('US', 'DE'))->treatment)->toBe(TaxTreatment::NotRegistered)
        // ...and one that holds OSS (non-Union) or IOSS as registered in every member state.
        ->and((string) app(TaxCalculator::class)->assess(gateQuery('US', 'DE', oss: new OssStatus(registered: true)))->tax->getAmount())->toBe('19.00');
});

it('leaves an EU-established seller on the Union rules it already applies', function (): void {
    // Destination under OSS, or origin under the micro-business relief — the regime
    // decides, and both are the seller's to collect.
    expect((string) app(TaxCalculator::class)->assess(gateQuery('DK', 'DE', oss: new OssStatus(registered: true)))->tax->getAmount())->toBe('19.00')
        ->and((string) app(TaxCalculator::class)->assess(gateQuery('DK', 'DE', oss: new OssStatus(registered: false, thresholdExceeded: false)))->tax->getAmount())->toBe('25.00')
        ->and((string) app(TaxCalculator::class)->assess(gateQuery('DK', 'DK'))->tax->getAmount())->toBe('25.00');
});

it('lets the platform collect where the register says the law makes it liable', function (): void {
    // The UK has made online marketplaces liable since 2021. Charging as well taxes
    // the buyer twice.
    $a = app(TaxCalculator::class)->assess(gateQuery('DK', 'GB', marketplace: true));

    expect($a->treatment)->toBe(TaxTreatment::MarketplaceFacilitated)
        ->and((string) $a->tax->getAmount())->toBe('0.00');
});

it('does not take a marketplace assertion where no rule makes the platform liable', function (): void {
    // No Norwegian marketplace rule is published: the assertion alone moves nothing,
    // and a seller unregistered in Norway still collects nothing.
    expect(app(TaxCalculator::class)->assess(gateQuery('DK', 'NO', marketplace: true))->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and(app(TaxCalculator::class)->assess(gateQuery('DK', 'NO', registeredIn: ['NO'], marketplace: true))->treatment)->toBe(TaxTreatment::Standard);
});

it('still reverse-charges a validated business, registered or not', function (): void {
    expect(app(TaxCalculator::class)->assess(gateQuery('DK', 'FR', customer: CustomerType::Business, validated: true))->treatment)->toBe(TaxTreatment::ReverseCharge);
});

it('marks intra-EU goods to a business as an Article 138 supply, and services as a reverse charge', function (TaxClass $class, string $code, string $article): void {
    // Goods are exempt on dispatch and acquired by the customer (Art. 138); services
    // are reverse-charged (Art. 196). Same total, different provision, different
    // mention, and a different line on the return and the EC Sales List.
    $a = app(TaxCalculator::class)->assess(gateQuery('DK', 'FR', customer: CustomerType::Business, validated: true, class: $class));

    expect($a->mentions[0]->code)->toBe($code)
        ->and($a->mentions[0]->reference)->toContain($article);
})->with([
    'tangible goods' => [TaxClass::GeneralGoods, 'intra_community_supply', 'Article 138'],
    'a consultancy service' => [TaxClass::ProfessionalService, 'reverse_charge', 'Article 196'],
    'an electronic service' => [TaxClass::DigitalService, 'reverse_charge', 'Article 196'],
]);

it('leaves the seller charging where a marketplace mandate reaches only some sales, and says so', function (): void {
    // France deems the platform liable under Article 14a — for an imported consignment
    // worth at most EUR 150, or goods within the Community sold by a seller
    // established outside it. Read as a mandate over everything it hands the tax to a
    // platform the Directive does not reach, and nobody collects.
    $a = app(TaxCalculator::class)->assess(gateQuery('FR', 'FR', marketplace: true));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('20.00')
        ->and($a->rate?->limitedBy)->toBe(RateLimit::MarketplaceLiabilityUnread)
        ->and($a->rate?->confidence)->toBe(Confidence::Derived);
});

it('still hands an unconditional mandate to the platform', function (): void {
    $a = app(TaxCalculator::class)->assess(gateQuery('GB', 'GB', marketplace: true));

    expect($a->treatment)->toBe(TaxTreatment::MarketplaceFacilitated)
        ->and((string) $a->tax->getAmount())->toBe('0.00');
});
