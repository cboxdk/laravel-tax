<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxExemption;
use Cbox\Tax\ValueObjects\TaxQuery;

// Art. 226(11a) of the VAT Directive requires the words "Reverse charge" on the
// invoice, and the CJEU held in Luxury Trust Automobil (C-247/21) that a missing
// mention CANNOT be corrected retroactively. So this is not a formatting nicety:
// a caller printing our English `reason` string instead produces an invoice that
// stays defective.

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

function mentionQuery(
    string $seller,
    string $buyer,
    CustomerType $customer = CustomerType::Business,
    ?TaxExemption $exemption = null,
): TaxQuery {
    return new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode($buyer)),
        customer: $customer,
        seller: new SellerRegistrations(new CountryCode($seller)),
        category: TaxClass::DigitalService,
        customerTaxIdValidated: $customer === CustomerType::Business,
        exemption: $exemption,
    );
}

it('carries the mandatory reverse-charge wording, with the provision', function () {
    $assessment = $this->tax->assess(mentionQuery('DE', 'FR'));

    expect($assessment->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and($assessment->mentions)->toHaveCount(1)
        ->and($assessment->mentions[0]->code)->toBe('reverse_charge')
        // The exact words the Directive requires — not a paraphrase.
        ->and($assessment->mentions[0]->text)->toBe('Reverse charge')
        ->and($assessment->mentions[0]->reference)->toBe('Article 196 of Council Directive 2006/112/EC')
        ->and($assessment->mentionLines())->toBe(['Reverse charge — Article 196 of Council Directive 2006/112/EC']);
});

it('does not cite the EU Directive for a regime it does not govern', function () {
    // A UK or Norwegian reverse charge is not Art. 196. Printing a Directive
    // citation on a non-EU invoice would be a defect a reader would trust, so the
    // shared branch emits nothing unless a regime supplies its own.
    $assessment = $this->tax->assess(mentionQuery('DE', 'GB'));

    expect($assessment->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and($assessment->mentions)->toBe([]);
});

it('says nothing on an ordinary taxed supply', function () {
    $assessment = $this->tax->assess(mentionQuery('DE', 'DE', CustomerType::Consumer));

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and($assessment->mentions)->toBe([]);
});

it('names the certificate an exemption rests on', function () {
    // The zero on the invoice is only defensible next to the certificate that
    // produced it — which is the first thing an auditor asks for.
    $exemption = $this->taxExemption(reference: 'RESALE-4471', countries: ['DE']);

    $assessment = $this->tax->assess(mentionQuery('DE', 'DE', CustomerType::Business, $exemption));

    $this->assertExempt($assessment, 'RESALE-4471');

    expect($assessment->mentions)->toHaveCount(1)
        ->and($assessment->mentions[0]->code)->toBe('exempt_certificate')
        ->and($assessment->mentions[0]->text)->toContain('RESALE-4471');
});

it('keeps the mentions when the tax point is stamped on', function () {
    // The calculator rebuilds the assessment to record the date it resolved
    // against. A rebuild that dropped the mandatory wording would be the worst
    // possible bug here: silent, and only visible on the printed invoice.
    $assessment = $this->tax->assess(mentionQuery('DE', 'FR'));

    expect($assessment->taxPoint)->not->toBeNull()
        ->and($assessment->mentions)->toHaveCount(1);
});
