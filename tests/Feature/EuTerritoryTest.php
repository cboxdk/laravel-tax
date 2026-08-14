<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\EuTerritories;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Territories\StaticEuTerritories;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

// Ten territories sit inside a Member State and outside its VAT rules. Before
// this, a delivery to Tenerife was charged Spanish VAT — which is not a rate
// error, it is the wrong tax: the liability is invented, and the customer
// separately owes IGIC that nobody collected.
//
// Ceuta and Melilla were worse than unreachable: the geo repository resolves them
// as ordinary Spanish subdivisions with isEuMember = true, so the engine had a
// confident wrong answer rather than no answer.
//
// The territories cannot be named by subdivision. The addressing reference data
// carries no subdivisions at all for Portugal, Finland, France or Greece — so the
// Azores, Madeira, Åland and Corsica are unreachable that way, while every one of
// them has had its own postal range for decades.

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

function delivery(string $country, ?string $postalCode): TaxQuery
{
    return new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode($country)),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode($country)),
        postalCode: $postalCode,
    );
}

it('charges no EU VAT on a delivery to the Canary Islands', function () {
    // 38xxx is Santa Cruz de Tenerife. The supply is an export from the EU VAT
    // area, so nothing is due here — and the reason names IGIC, which is what the
    // customer actually owes, rather than leaving a bare zero.
    $assessment = $this->tax->assess(delivery('ES', '38001'));

    expect($assessment->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and((string) $assessment->tax->getAmount())->toBe('0.00')
        ->and($assessment->reason)->toContain('Canary Islands')
        ->and($assessment->reason)->toContain('IGIC');
});

it('covers both Canary provinces, not just the one', function () {
    // 35xxx is Las Palmas. Half the archipelago would otherwise be taxed as
    // mainland Spain.
    expect($this->tax->assess(delivery('ES', '35001'))->treatment)->toBe(TaxTreatment::ZeroRated);
});

it('stops charging Spanish VAT in Ceuta and Melilla', function () {
    // These two resolve as ordinary Spanish subdivisions with isEuMember = true,
    // so the engine did not merely fail to place them — it placed them wrongly and
    // charged 21%.
    expect($this->tax->assess(delivery('ES', '51001'))->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($this->tax->assess(delivery('ES', '52001'))->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($this->tax->assess(delivery('ES', '51001'))->reason)->toContain('IPSI');
});

it('leaves mainland Spain alone', function () {
    // Madrid is 28xxx. The territories are the exception, and a seam that caught
    // anything else would break every ordinary Spanish sale.
    expect($this->tax->assess(delivery('ES', '28001'))->treatment)->toBe(TaxTreatment::Standard);
});

it('recognises the single-municipality territories', function () {
    // Büsingen, Heligoland, Livigno and Campione d'Italia are one postcode each,
    // and are outside the VAT area for reasons that predate the Union.
    expect($this->tax->assess(delivery('DE', '78266'))->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($this->tax->assess(delivery('DE', '27498'))->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($this->tax->assess(delivery('IT', '23041'))->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($this->tax->assess(delivery('IT', '22061'))->treatment)->toBe(TaxTreatment::ZeroRated);
});

it('does not catch the neighbouring postcodes of those municipalities', function () {
    // A single-code territory must match that code and nothing near it. Konstanz
    // is 78462 and Como 22100 — both ordinary.
    expect($this->tax->assess(delivery('DE', '78462'))->treatment)->toBe(TaxTreatment::Standard)
        ->and($this->tax->assess(delivery('IT', '22100'))->treatment)->toBe(TaxTreatment::Standard);
});

it('treats a missing postcode as unplaceable, not as mainland', function () {
    // The honest reading of no postcode is "we cannot tell", and the national
    // rules are what the engine applies — but it must not be because a missing
    // code was taken as proof of mainland. The seam returns null; the deduction
    // that this is Spain is the regime's, made explicitly.
    $territories = new StaticEuTerritories;

    expect($territories->for(new CountryCode('ES'), null))->toBeNull()
        ->and($territories->for(new CountryCode('ES'), ''))->toBeNull();
});

// ---- The territories that keep their own rates -------------------------------

it('identifies the Portuguese islands, which are inside the VAT area', function () {
    // A different case from Spain's, and the reason territory is modelled rather
    // than "special = no tax": the Azores charge 16% and Madeira 22% where the
    // mainland charges 23%. They are IN the VAT area with their own rates.
    $territories = new StaticEuTerritories;

    $madeira = $territories->for(new CountryCode('PT'), '9000-001');
    $azores = $territories->for(new CountryCode('PT'), '9500-001');

    expect($madeira?->name)->toBe('Madeira')
        ->and($madeira?->outsideVatArea)->toBeFalse()
        ->and($madeira?->standardRate)->toBe('22')
        ->and($azores?->standardRate)->toBe('16')
        ->and($territories->for(new CountryCode('PT'), '1000-001'))->toBeNull();  // Lisbon
});

it('is bound by default so a host gets this without wiring anything', function () {
    expect($this->app->make(EuTerritories::class))->toBeInstanceOf(StaticEuTerritories::class);
});

it('charges Madeira its own 22% rather than the mainland 23%', function () {
    // The other kind of territory: inside the VAT area, own rates. Identifying it
    // was only half the job — until now the rate came from Portugal.
    $assessment = $this->tax->assess(delivery('PT', '9000-001'));

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $assessment->rate?->percentage)->toBe('22')
        ->and((string) $assessment->tax->getAmount())->toBe('22.00')
        ->and($assessment->reason)->toContain('Madeira');
});

it('charges the Azores 16%, the largest gap of the three', function () {
    // Seven points below the mainland. Charged as Portugal, every invoice into the
    // Azores over-collects by 7%.
    expect((string) $this->tax->assess(delivery('PT', '9500-001'))->rate?->percentage)->toBe('16');
});

it('leaves mainland Portugal on its own rate', function () {
    // Lisbon is 1000-xxx.
    expect((string) $this->tax->assess(delivery('PT', '1000-001'))->rate?->percentage)->toBe('23');
});

it('marks the regional rate as derived, not authoritative', function () {
    // It comes from a shipped territory map rather than from a rate feed, and the
    // confidence should say so — an operator filtering on Authoritative is asking
    // exactly the right question.
    expect($this->tax->assess(delivery('PT', '9000-001'))->rate?->confidence)
        ->toBe(Confidence::Derived);
});
