<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\PlaceOfSupplyRule;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\ValueObjects\OssStatus;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

// Art. 45 of the VAT Directive: the place of supply of a service to a NON-TAXABLE
// person is where the SUPPLIER is established. Destination is the carve-out —
// Art. 58 for telecoms/broadcasting/electronic services, Art. 33(a) for goods —
// not the rule. The engine had it the other way round.

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

function euSupply(
    string $sellerCountry,
    string $buyerCountry,
    TaxClass $category,
    CustomerType $customer = CustomerType::Consumer,
    ?OssStatus $oss = null,
): TaxQuery {
    return new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode($buyerCountry)),
        customer: $customer,
        seller: new SellerRegistrations(new CountryCode($sellerCountry), oss: $oss),
        category: $category,
        customerTaxIdValidated: $customer === CustomerType::Business,
    );
}

it('taxes a cross-border B2C consultancy where the SUPPLIER is established', function () {
    // A German consultancy invoicing a French consumer owes German VAT, and owes no
    // OSS obligation for that supply at all. The engine charged French VAT.
    $assessment = $this->tax->assess(euSupply('DE', 'FR', TaxClass::ProfessionalService));

    expect($assessment->placeOfSupply->country->value)->toBe('DE')
        ->and((string) $assessment->rate?->percentage)->toBe('19')
        ->and((string) $assessment->tax->getAmount())->toBe('19.00');
});

it('keeps electronically supplied services at the customer, which is the carve-out', function () {
    // Art. 58. This is the case the old blanket rule got right, and it must not
    // move.
    $assessment = $this->tax->assess(euSupply('DE', 'FR', TaxClass::DigitalService));

    expect($assessment->placeOfSupply->country->value)->toBe('FR')
        ->and((string) $assessment->rate?->percentage)->toBe('20');
});

it('keeps goods at the customer under the distance-sales rule', function () {
    // Art. 33(a).
    $assessment = $this->tax->assess(euSupply('DE', 'FR', TaxClass::GeneralGoods));

    expect($assessment->placeOfSupply->country->value)->toBe('FR');
});

it('leaves B2B alone — the general rule there is the customer, and reverse charge handles it', function () {
    // Art. 44, and a validated cross-border B2B supply reverse-charges regardless.
    $assessment = $this->tax->assess(
        euSupply('DE', 'FR', TaxClass::ProfessionalService, CustomerType::Business),
    );

    expect($assessment->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and((string) $assessment->tax->getAmount())->toBe('0.00');
});

it('taxes a domestic consultancy at home, unchanged', function () {
    $assessment = $this->tax->assess(euSupply('DE', 'DE', TaxClass::ProfessionalService));

    expect($assessment->placeOfSupply->country->value)->toBe('DE')
        ->and((string) $assessment->rate?->percentage)->toBe('19');
});

// ---- Art. 59c relief covers what it covers, and no more -------------------

it('does not grant micro-business relief to a supply Art. 59c never covered', function () {
    // Art. 59c disapplies Art. 33(a) and Art. 58 — goods and TBE services. It is not
    // a general small-seller exemption. For a general service the answer is the
    // supplier's country anyway under Art. 45, so the figure agrees; what changes is
    // that it is reached by the right rule, and would not follow the relief if the
    // seller opted into OSS.
    $belowThreshold = new OssStatus(registered: false, thresholdExceeded: false);

    $relieved = $this->tax->assess(
        euSupply('DE', 'FR', TaxClass::ProfessionalService, oss: $belowThreshold),
    );

    // Opted INTO OSS: relief no longer applies, but Art. 45 still sources at the
    // supplier — proving the outcome comes from the place-of-supply rule and not
    // from the relief.
    $optedIn = $this->tax->assess(
        euSupply('DE', 'FR', TaxClass::ProfessionalService, oss: new OssStatus(registered: true, thresholdExceeded: false)),
    );

    expect($relieved->placeOfSupply->country->value)->toBe('DE')
        ->and($optedIn->placeOfSupply->country->value)->toBe('DE');
});

it('still grants relief to the goods and TBE supplies it does cover', function () {
    $belowThreshold = new OssStatus(registered: false, thresholdExceeded: false);

    $digital = $this->tax->assess(euSupply('DE', 'FR', TaxClass::DigitalService, oss: $belowThreshold));

    expect($digital->placeOfSupply->country->value)->toBe('DE')
        ->and((string) $digital->rate?->percentage)->toBe('19');
});

// ---- The classification itself --------------------------------------------

it('classifies every category under a place-of-supply rule', function () {
    // Goods are the default, so a new category added without thought lands on
    // destination — which is right for goods and is where the old code put
    // everything anyway. The named ones are the ones that had to be decided.
    expect(TaxClass::ProfessionalService->placeOfSupplyRule())->toBe(PlaceOfSupplyRule::SupplierEstablishment)
        ->and(TaxClass::SoftwareCustom->placeOfSupplyRule())->toBe(PlaceOfSupplyRule::SupplierEstablishment)
        ->and(TaxClass::DigitalService->placeOfSupplyRule())->toBe(PlaceOfSupplyRule::Destination)
        ->and(TaxClass::AiApi->placeOfSupplyRule())->toBe(PlaceOfSupplyRule::Destination)
        ->and(TaxClass::GeneralGoods->placeOfSupplyRule())->toBe(PlaceOfSupplyRule::Destination)
        ->and(TaxClass::Book->placeOfSupplyRule())->toBe(PlaceOfSupplyRule::Destination)
        ->and(TaxClass::RepairService->placeOfSupplyRule())->toBe(PlaceOfSupplyRule::WhereProvided)
        ->and(TaxClass::PersonalCare->placeOfSupplyRule())->toBe(PlaceOfSupplyRule::WhereProvided);
});
