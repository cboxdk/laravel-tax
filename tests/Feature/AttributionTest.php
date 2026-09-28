<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\DecisionFacts;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\SupplyRoute;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;

/*
 * WHO ACCOUNTS FOR THE TAX when a supplier not established in a member state sells to a
 * business there (Art. 194). Every state has a rule and they differ in what it covers:
 * Germany reverses work deliveries and services by foreign businesses but not plain
 * deliveries of goods; France reverses goods too, where the customer is registered in
 * France. The engine reverse-charged every such supply, citing Art. 196 — and goods
 * that never left the state were called an intra-Community supply.
 */

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/attribution');

    $recipient = ['accountedForBy' => 'recipient', 'whenSupplierNotEstablished' => true, 'whenCustomerIs' => 'taxable_person'];

    FakeRegister::at(config('tax.register.store'))
        ->rate('eu:DE', '19', from: '1990-01-01')
        ->rate('eu:FR', '20', from: '1990-01-01')
        ->rate('eu:BE', '21', from: '1990-01-01')
        ->rate('eu:PL', '23', from: '1990-01-01')
        ->rule('eu:DE', 'attribution', $recipient, from: '2020-01-01', extra: [
            'citation' => '§ 13b UStG',
            'conditions' => [['kind' => 'applies_only', 'says' => 'Werklieferungen und sonstige Leistungen', 'predicate' => ['any' => [
                ['fact' => 'supply.isService', 'op' => 'eq', 'value' => true],
                ['fact' => 'supply.isWerklieferung', 'op' => 'eq', 'value' => true],
            ]]]],
        ])
        ->rule('eu:FR', 'attribution', $recipient, from: '2020-01-01', extra: [
            'citation' => 'CGI art. 283-1',
            'conditions' => [['kind' => 'applies_only', 'says' => 'preneur identifié en France', 'predicate' => ['fact' => 'recipient.registeredForVatInTheState', 'op' => 'eq', 'value' => true]]],
        ])
        // Belgium: a rule that does not say which supplies it covers.
        ->rule('eu:BE', 'attribution', $recipient, from: '2020-01-01')
        ->install();
});

function supplyTo(string $country, TaxClass $class = TaxClass::GeneralGoods, ?string $shipFrom = null, ?string $vat = null, ?DecisionFacts $facts = null, array $registeredIn = [], ?string $performedAt = null): TaxAssessment
{
    $geo = app(JurisdictionRepository::class);

    return app(TaxCalculator::class)->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $geo->find(new CountryCode($country)),
        customer: CustomerType::Business,
        // Established in the US: established in no member state.
        seller: new SellerRegistrations(new CountryCode('US'), array_map(fn (string $c): SellerRegistration => new SellerRegistration(new CountryCode($c)), $registeredIn)),
        category: $class,
        customerTaxIdValidated: true,
        suppliedAt: new DateTimeImmutable('2026-09-28'),
        route: new SupplyRoute(shipFrom: $shipFrom === null ? null : $geo->find(new CountryCode($shipFrom))),
        performedAt: $performedAt === null ? null : $geo->find(new CountryCode($performedAt)),
        facts: $facts ?? new DecisionFacts,
        customerTaxId: $vat,
    ));
}

it('leaves plain goods in Germany to the supplier, who charges German VAT', function (): void {
    // Germany's rule reverses services and work deliveries, not plain goods: told the
    // goods are not a work delivery, the supplier registers and charges, or collects
    // nothing if it has not registered.
    $plain = new DecisionFacts(['supply.isWerklieferung' => false]);
    $registered = supplyTo('DE', shipFrom: 'DE', facts: $plain, registeredIn: ['DE']);
    $unregistered = supplyTo('DE', shipFrom: 'DE', facts: $plain);
    // Not told: goods might be a work delivery, so the rule is unsettled — flagged,
    // naming the fact, never guessed.
    $untold = supplyTo('DE', shipFrom: 'DE', registeredIn: ['DE']);

    expect($registered->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $registered->tax->getAmount())->toBe('19.00')
        ->and($unregistered->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($untold->limitedBy)->toBe(RateLimit::AttributionUnsettled)
        ->and($untold->reason)->toContain('supply.isWerklieferung');
});

it('reverses a German work delivery and a service performed there, citing the provision', function (): void {
    $werklieferung = supplyTo('DE', shipFrom: 'DE', facts: new DecisionFacts(['supply.isWerklieferung' => true]));
    // An event in Germany for a French business: taxed where performed, and Germany's
    // rule puts it on the customer.
    $event = supplyTo('FR', TaxClass::CulturalAdmission, performedAt: 'DE');

    expect($werklieferung->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and($werklieferung->placeOfSupply->country->value)->toBe('DE')
        ->and($werklieferung->mentions[0]->reference)->toBe('§ 13b UStG')
        ->and($event->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and($event->placeOfSupply->country->value)->toBe('DE')
        ->and($event->limitedBy)->toBeNull();
});

it('reads the customer\'s registration from its VAT number, and never a "no" from another state\'s', function (): void {
    $french = supplyTo('FR', shipFrom: 'FR', vat: 'FR12345678901');
    // A German number says nothing about whether the customer is registered in France.
    $german = supplyTo('FR', shipFrom: 'FR', vat: 'DE123456789');

    expect($french->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and($french->mentions[0]->reference)->toBe('CGI art. 283-1')
        ->and($french->limitedBy)->toBeNull()
        // Unsettled: today's answer — a reverse charge, on Art. 194 for goods that
        // stayed in France — flagged, with the fact that would settle it.
        ->and($german->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and($german->mentions[0]->reference)->toBe('Article 194 of Council Directive 2006/112/EC')
        ->and($german->limitedBy)->toBe(RateLimit::AttributionUnsettled)
        ->and($german->reason)->toContain('recipient.registeredForVatInTheState');
});

it('flags a rule that does not say which supplies it covers', function (): void {
    $belgian = supplyTo('BE', shipFrom: 'BE');

    expect($belgian->limitedBy)->toBe(RateLimit::AttributionUnsettled)
        ->and($belgian->reason)->toContain('does not say which supplies it covers');
});

it('still treats goods that cross a border as an intra-Community supply', function (): void {
    $dispatched = supplyTo('DE', shipFrom: 'PL');

    expect($dispatched->treatment)->toBe(TaxTreatment::IntraCommunitySupply)
        ->and($dispatched->limitedBy)->toBeNull();
});

it('puts an unsettled answer where a review looks', function (): void {
    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        app(JurisdictionRepository::class)->find(new CountryCode('BE')),
        CustomerType::Business,
        new SellerRegistrations(new CountryCode('US')),
        Pricing::Exclusive,
        [new SupplyLine('crate', Money::of('100.00', 'EUR'))],
        customerTaxIdValidated: true,
        suppliedAt: new DateTimeImmutable('2026-09-28'),
        route: new SupplyRoute(shipFrom: app(JurisdictionRepository::class)->find(new CountryCode('BE'))),
    ));

    expect($order->needsReview())->toBeTrue()
        ->and($order->limits())->toBe([RateLimit::AttributionUnsettled]);
});
