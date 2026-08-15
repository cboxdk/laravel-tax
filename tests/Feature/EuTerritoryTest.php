<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\EuTerritories;
use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Territories\StaticEuTerritories;
use Cbox\Tax\ValueObjects\EuTerritory;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;
use DateTimeImmutable;

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

// ---- The territories' own rates, at every level --------------------------------

it('charges Madeira its own reduced rate, not the mainland band', function () {
    // The gap this closes. The regime substituted the STANDARD rate only, so a
    // Madeira grocery line kept mainland Portugal's 6% with a caveat saying it might
    // be two points high. It was: Madeira charges 4%.
    //
    // Rates from Ofício Circulado n.º 25045 (2024-12-06), Anexo — the Portuguese tax
    // authority's own table. CIVA art. 18 n.º 3 deliberately does not carry them; it
    // delegates to the regional assemblies, which is why reading the tax code alone
    // finds nothing.
    $territory = new StaticEuTerritories()->for(new CountryCode('PT'), '9000-001');

    expect($territory?->name)->toBe('Madeira')
        ->and($territory?->rateFor('23'))->toBe('22')
        ->and($territory?->rateFor('13'))->toBe('12')
        ->and($territory?->rateFor('6'))->toBe('4');
});

it('prices a Madeira supply with the reduced rate in force on its date', function () {
    // Madeira's reduced rate went from 5% to 4% on 2024-10-01 (DLR 6/2024/M art.
    // 21.º, effective under art. 121.º n.º 2). An invoice corrected afterwards must
    // reprice at what applied then.
    $before = new StaticEuTerritories()->for(new CountryCode('PT'), '9000-001', new DateTimeImmutable('2024-06-01'));
    $after = new StaticEuTerritories()->for(new CountryCode('PT'), '9000-001', new DateTimeImmutable('2024-10-01'));

    expect($before?->rateFor('6'))->toBe('5')
        ->and($after?->rateFor('6'))->toBe('4');
});

it('charges the Azores 30% below every national level', function () {
    // DLR 15-A/2021/A cut the national rates by 30% from 2021-07-01, turning
    // 6/13/23 into 4/9/16 — one rule, three levels, and the engine must apply it at
    // whichever level the supply lands on.
    $territory = new StaticEuTerritories()->for(new CountryCode('PT'), '9500-001');

    expect($territory?->name)->toBe('Azores')
        ->and($territory?->rateFor('23'))->toBe('16')
        ->and($territory?->rateFor('13'))->toBe('9')
        ->and($territory?->rateFor('6'))->toBe('4');
});

it('leaves a level it does not carry on the mainland band, and says so', function () {
    // Deny-by-default at the level lookup: an unknown mainland rate is not silently
    // mapped to the standard one.
    expect(new StaticEuTerritories()->for(new CountryCode('PT'), '9000-001')?->rateFor('99'))->toBeNull();
});

it('lets a host rebind the territory list and actually reach the regime', function () {
    // The provider bound EuTerritories while DefaultRegimeRegistry hardcoded
    // StaticEuTerritories, so a host following the documented instruction to rebind
    // it changed nothing. A silent no-op on a seam the docs point at, and the
    // failure mode is mainland VAT charged on a supply outside the VAT area — which
    // is the whole reason someone would rebind it.
    $this->app->instance(EuTerritories::class, new class implements EuTerritories
    {
        public function for(CountryCode $country, ?string $postalCode, ?DateTimeImmutable $at = null): ?EuTerritory
        {
            // Everything in France is outside the VAT area, as far as this says.
            return $country->value === 'FR'
                ? new EuTerritory('FR-XX', 'Nowhere', true)
                : null;
        }
    });

    // The registry is a singleton and may already have been resolved, in which case
    // it is holding the territory list built at that moment. Forgetting it is what
    // a host does implicitly by binding in a provider before anything resolves.
    // Both, and in this order. TaxCalculator is a singleton holding the registry,
    // so forgetting only the registry leaves an already-built calculator pointing
    // at the old territory list. A host binding in a provider gets this for free
    // because nothing has resolved yet.
    $this->app->forgetInstance(RegimeRegistry::class);
    $this->app->forgetInstance(TaxCalculator::class);

    $assessment = $this->app->make(TaxCalculator::class)->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $this->app->make(JurisdictionRepository::class)->find(new CountryCode('FR')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('FR')),
        postalCode: '75001',
    ));

    expect($assessment->tax->getAmount()->toFloat())->toBe(0.0);
});
