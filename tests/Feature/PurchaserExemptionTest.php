<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\PurchaserType;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\DecisionFacts;
use Cbox\Tax\ValueObjects\InvoiceMention;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;

/*
 * Who is buying can change the answer, and each place decides how: a charity is
 * exempt in Texas against form 01-339, a direct pay permit holder in Arkansas pays
 * the tax itself, a diplomat in a member state is relieved under Art. 151 within the
 * host state's limits. Stating the purchaser asks the place's rule; it never assumes
 * an exemption. The rules below are cadastre's four published samples.
 */

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/purchaser-exemption');

    $charity = [
        'purchaser' => 'charitable_organization',
        'effect' => 'exempt',
        'certificate' => ['form' => '01-339', 'required' => true],
        'conditions' => [
            [
                'kind' => 'recipient_is',
                'says' => 'an organization created for religious, educational, or charitable purposes …',
                'names' => 'a charitable organisation, or one holding a federal 501(c) exemption',
                'predicate' => ['any' => [
                    ['fact' => 'recipient.netEarningsBenefitNoPrivateShareholderOrIndividual', 'op' => 'eq', 'value' => true],
                    ['fact' => 'recipient.federalIncomeTaxExemptUnderIrc501c', 'op' => 'in', 'value' => ['3', '4', '8', '10', '19']],
                ]],
            ],
            [
                'kind' => 'use_is',
                'says' => 'and the items purchased, leased, or rented are related to the purpose of the organization',
                'names' => 'only items related to the purpose of the organization',
                'predicate' => ['fact' => 'use.relatedToThePurposeOfTheOrganization', 'op' => 'eq', 'value' => true],
            ],
        ],
        'citation' => 'Tex. Tax Code § 151.310(a)(1)-(2)',
        'says' => '(a) A taxable item sold, leased, or rented to … any of the following organizations is exempted',
    ];

    FakeRegister::at(config('tax.register.store'))
        ->rate('us:TX', '6.25', from: '1990-01-01')
        ->rate('us:AR', '6.5', from: '1990-01-01')
        ->rate('us:AL', '4', from: '1990-01-01')
        ->rate('eu:DK', '25', from: '1990-01-01')
        ->rate('eu:SE', '25', from: '1990-01-01')
        ->rate('eu:FR', '20', from: '1990-01-01')
        ->rule('eu:FR', 'purchaser_exemption', [
            'purchaser' => 'international_body', 'effect' => 'exempt_with_deduction',
            'certificate' => ['form' => null, 'required' => false],
            'conditions' => [[
                'kind' => 'applies_only_to', 'says' => 'livraisons de biens',
                'predicate' => ['fact' => 'supply.isGoods', 'op' => 'eq', 'value' => true],
            ]],
            'citation' => 'CGI 262-00 bis', 'says' => 'illustrative',
        ], from: '2007-01-01')
        ->rule('us:TX', 'purchaser_exemption', $charity, from: '2020-01-01')
        ->rule('us:AR', 'purchaser_exemption', [
            'purchaser' => 'direct_pay_permit', 'effect' => 'taxable', 'accountedForBy' => 'customer',
            'certificate' => ['form' => 'Arkansas direct pay permit', 'required' => true],
            'citation' => '26 CAR § 30-1220',
            'says' => '(2) A use vendor or sales tax retailer selling to the holder of a valid direct pay permit is not responsible for the collection of the tax.',
        ], from: '2020-01-01')
        ->rule('us:AL', 'purchaser_exemption', [
            'purchaser' => 'industrial_production', 'effect' => 'reduced', 'rate' => '0.75', 'accountedForBy' => 'customer',
            'certificate' => ['form' => 'Sales and Use Tax Exemption Certificate for an Industrial or Research Enterprise Project', 'required' => true],
            'conditions' => [[
                'kind' => 'recipient_is', 'says' => 'For Ch. 9B and 9G projects granted a sales and use tax abatement …',
                'predicate' => ['all' => [
                    ['fact' => 'evidence.alabamaAbatementChapter', 'op' => 'in', 'value' => ['40-9B', '40-9G']],
                    ['unsettled' => ['says' => 'are abated on qualifying tangible personal property incorporated into the project']],
                ]],
            ]],
            'citation' => 'Code of Ala. 1975, Title 40, Chapters 9B and 9G',
            'says' => 'File and remit the Unabated State Sales Tax Return for the 0.75% state tax',
        ], from: '2020-01-01')
        ->rule('us:AL', 'purchaser_exemption', [
            'purchaser' => 'agricultural_production', 'effect' => 'reduced', 'rate' => '1.5',
            'certificate' => ['form' => null, 'required' => false],
            'citation' => 'illustrative', 'says' => 'illustrative',
        ], from: '2020-01-01')
        ->rule('eu', 'purchaser_exemption', [
            'purchaser' => 'diplomatic_or_consular', 'effect' => 'exempt_with_deduction', 'route' => 'at_source_or_refund',
            'certificate' => ['form' => 'eu-282-2011-annex-ii', 'required' => true, 'requiredWhen' => [
                'fact' => 'recipient.establishedInTheState', 'op' => 'eq', 'value' => false,
            ]],
            'conditions' => [[
                'kind' => 'set_by_instrument',
                'says' => 'Pending the adoption of common tax rules, the exemptions … shall be subject to the limitations laid down by the host Member State.',
                'instrument' => ['kind' => 'regulation', 'madeBy' => 'the host Member State'],
            ]],
            'citation' => 'Directive 2006/112/EC Art. 151(1)(a)',
            'says' => 'the supply of goods or services under diplomatic and consular arrangements;',
        ], from: '2007-01-01')
        // Denmark's own rule, standing in for a member that has published its limits.
        ->rule('eu:DK', 'purchaser_exemption', [
            'purchaser' => 'diplomatic_or_consular', 'effect' => 'exempt_with_deduction',
            'certificate' => ['form' => 'eu-282-2011-annex-ii', 'required' => true, 'requiredWhen' => [
                'fact' => 'recipient.establishedInTheState', 'op' => 'eq', 'value' => false,
            ]],
            'citation' => 'Momsloven § 47',
            'says' => 'illustrative',
        ], from: '2007-01-01')
        ->install();
});

function purchase(string $country, ?string $state, ?PurchaserType $purchaser, array $facts = [], Pricing $pricing = Pricing::Exclusive, string $amount = '100.00'): TaxAssessment
{
    $geo = app(JurisdictionRepository::class);
    $place = $state === null ? $geo->find(new CountryCode($country)) : $geo->find(new CountryCode($country), new SubdivisionCode($state));

    return app(TaxCalculator::class)->assess(new TaxQuery(
        amount: Money::of($amount, $country === 'US' ? 'USD' : 'EUR'),
        pricing: $pricing,
        place: $place,
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode($country), $state === null ? [] : [new SellerRegistration(new CountryCode($country), new SubdivisionCode($state))]),
        suppliedAt: new DateTimeImmutable('2026-10-03'),
        facts: new DecisionFacts($facts),
        purchaser: $purchaser,
    ));
}

const TX_CHARITY = [
    'recipient.federalIncomeTaxExemptUnderIrc501c' => '3',
    'use.relatedToThePurposeOfTheOrganization' => true,
];

it('answers as before when nobody says who is buying', function (): void {
    $sale = purchase('US', 'US-TX', null, TX_CHARITY + ['evidence.holdsExemptionCertificate' => true]);

    expect($sale->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $sale->tax->getAmount())->toBe('6.25')
        ->and($sale->limitedBy)->toBeNull();
});

it('exempts a Texas charity that meets the rule and whose certificate the seller holds', function (): void {
    $sale = purchase('US', 'US-TX', PurchaserType::CharitableOrganization, TX_CHARITY + ['evidence.holdsExemptionCertificate' => true]);

    expect($sale->treatment)->toBe(TaxTreatment::Exempt)
        ->and((string) $sale->tax->getAmount())->toBe('0.00')
        ->and($sale->limitedBy)->toBeNull()
        ->and($sale->reason)->toContain('Tex. Tax Code § 151.310')
        ->and(array_map(fn (InvoiceMention $m): string => $m->code, $sale->mentions))->toBe(['exempt', 'exempt_certificate'])
        ->and($sale->mentions[1]->text)->toContain('01-339');
});

it('taxes the charity until the seller holds its certificate', function (): void {
    $sale = purchase('US', 'US-TX', PurchaserType::CharitableOrganization, TX_CHARITY);

    expect($sale->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $sale->tax->getAmount())->toBe('6.25')
        ->and($sale->limitedBy)->toBe(RateLimit::ExemptionCertificateMissing)
        ->and($sale->reason)->toContain('certificate 01-339')
        ->and($sale->openFacts)->toBe(['evidence.holdsExemptionCertificate']);
});

it('says which facts would settle the rule, and taxes meanwhile', function (): void {
    $sale = purchase('US', 'US-TX', PurchaserType::CharitableOrganization, ['evidence.holdsExemptionCertificate' => true]);

    expect($sale->treatment)->toBe(TaxTreatment::Standard)
        ->and($sale->limitedBy)->toBe(RateLimit::PurchaserExemptionUnsettled)
        ->and($sale->reason)->toContain('use.relatedToThePurposeOfTheOrganization')
        // By name, for a form to ask — not only in the reason's words.
        ->and($sale->openFacts)->toContain('use.relatedToThePurposeOfTheOrganization')
        ->and($sale->openFacts)->not->toContain('evidence.holdsExemptionCertificate')
        ->and(RateLimit::PurchaserExemptionUnsettled->callerCanClose())->toBeTrue();
});

it('taxes a purchase outside the exempt purpose without a flag', function (): void {
    $sale = purchase('US', 'US-TX', PurchaserType::CharitableOrganization, [
        'recipient.federalIncomeTaxExemptUnderIrc501c' => '3',
        'use.relatedToThePurposeOfTheOrganization' => false,
        'evidence.holdsExemptionCertificate' => true,
    ]);

    expect($sale->treatment)->toBe(TaxTreatment::Standard)
        ->and($sale->limitedBy)->toBeNull();
});

it('never assumes an exemption the register does not state', function (): void {
    $sale = purchase('US', 'US-TX', PurchaserType::Resale, ['evidence.holdsExemptionCertificate' => true]);

    expect($sale->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $sale->tax->getAmount())->toBe('6.25')
        ->and($sale->limitedBy)->toBe(RateLimit::PurchaserExemptionNotPublished)
        ->and(RateLimit::PurchaserExemptionNotPublished->callerCanClose())->toBeFalse();
});

it('charges a direct pay permit holder nothing, and says the tax is still due', function (): void {
    $sale = purchase('US', 'US-AR', PurchaserType::DirectPayPermit, ['evidence.holdsExemptionCertificate' => true]);

    expect($sale->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and($sale->treatment->taxWasDue())->toBeTrue()
        ->and((string) $sale->tax->getAmount())->toBe('0.00')
        ->and($sale->mentions[0]->code)->toBe('purchaser_accounts')
        ->and($sale->mentions[0]->reference)->toBe('26 CAR § 30-1220');
});

it('leaves Alabama\'s abatement unsettled where the register could not read a limb', function (): void {
    $sale = purchase('US', 'US-AL', PurchaserType::IndustrialProduction, [
        'evidence.alabamaAbatementChapter' => '40-9B',
        'evidence.holdsExemptionCertificate' => true,
    ]);

    expect($sale->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $sale->tax->getAmount())->toBe('4.00')
        ->and($sale->limitedBy)->toBe(RateLimit::PurchaserExemptionUnsettled)
        // The chapter was stated; what is open is the limb nobody can answer.
        ->and($sale->reason)->not->toContain('state evidence.alabamaAbatementChapter')
        ->and($sale->reason)->toContain('has not read');
});

it('charges a reduced rate the seller accounts for, exclusive and inclusive', function (): void {
    $exclusive = purchase('US', 'US-AL', PurchaserType::AgriculturalProduction);
    $inclusive = purchase('US', 'US-AL', PurchaserType::AgriculturalProduction, pricing: Pricing::Inclusive, amount: '101.50');

    expect($exclusive->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $exclusive->tax->getAmount())->toBe('1.50')
        ->and((string) $exclusive->gross->getAmount())->toBe('101.50')
        ->and((string) $inclusive->net->getAmount())->toBe('100.00')
        ->and((string) $inclusive->tax->getAmount())->toBe('1.50');
});

it('holds Art. 151 unsettled where the host member state has not published its limits', function (): void {
    $sale = purchase('SE', null, PurchaserType::DiplomaticOrConsular, ['evidence.holdsExemptionCertificate' => true]);

    expect($sale->treatment)->toBe(TaxTreatment::Standard)
        ->and($sale->limitedBy)->toBe(RateLimit::PurchaserExemptionUnsettled)
        ->and($sale->reason)->toContain('Art. 151(1)(a)')
        ->and($sale->reason)->toContain('has not read');
});

it('applies a member\'s own Art. 151 rule, asking for Annex II only of a recipient established elsewhere', function (): void {
    $local = purchase('DK', null, PurchaserType::DiplomaticOrConsular, ['recipient.establishedInTheState' => true]);
    $elsewhere = purchase('DK', null, PurchaserType::DiplomaticOrConsular, ['recipient.establishedInTheState' => false]);
    $unknown = purchase('DK', null, PurchaserType::DiplomaticOrConsular);

    expect($local->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($local->mentions[0]->reference)->toBe('Momsloven § 47')
        ->and($elsewhere->limitedBy)->toBe(RateLimit::ExemptionCertificateMissing)
        ->and($unknown->limitedBy)->toBe(RateLimit::ExemptionCertificateMissing);
});

it('states the purchaser and its facts once for a whole order', function (): void {
    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        app(JurisdictionRepository::class)->find(new CountryCode('US'), new SubdivisionCode('US-TX')),
        CustomerType::Business,
        new SellerRegistrations(new CountryCode('US'), [new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-TX'))]),
        Pricing::Exclusive,
        [
            new SupplyLine('chairs', Money::of('100.00', 'USD')),
            // A line's own fact wins over the document's.
            new SupplyLine('yacht', Money::of('100.00', 'USD'), facts: new DecisionFacts(['use.relatedToThePurposeOfTheOrganization' => false])),
        ],
        suppliedAt: new DateTimeImmutable('2026-10-03'),
        purchaser: PurchaserType::CharitableOrganization,
        facts: new DecisionFacts(TX_CHARITY + ['evidence.holdsExemptionCertificate' => true]),
    ));

    expect($order->lines[0]->assessment->treatment)->toBe(TaxTreatment::Exempt)
        ->and($order->lines[1]->assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $order->tax()->getAmount())->toBe('6.25');
});

it('knows a supply of goods from its category, without being told again', function (): void {
    // France relieves an international body's purchases of goods. The line is filed
    // under goods; asking the caller to state `supply.isGoods` as well left every
    // such sale unsettled until it repeated what the category already said.
    $goods = purchase('FR', null, PurchaserType::InternationalBody);
    $statedOtherwise = purchase('FR', null, PurchaserType::InternationalBody, ['supply.isGoods' => false]);

    expect($goods->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($goods->limitedBy)->toBeNull()
        // A stated fact wins over the one the category implies.
        ->and($statedOtherwise->treatment)->toBe(TaxTreatment::Standard);
});
