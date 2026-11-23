<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\EuTerritories;
use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\Territories\StaticEuTerritories;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\EuTerritory;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyRoute;
use Cbox\Tax\ValueObjects\TaxAssessment;
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

beforeEach(function (): void {
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

it('charges no EU VAT on a delivery to the Canary Islands', function (): void {
    // 38xxx is Santa Cruz de Tenerife. The supply is an export from the EU VAT
    // area, so nothing is due here — and the reason names IGIC, which is what the
    // customer actually owes, rather than leaving a bare zero.
    $assessment = $this->tax->assess(delivery('ES', '38001'));

    expect($assessment->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and((string) $assessment->tax->getAmount())->toBe('0.00')
        ->and($assessment->reason)->toContain('Canary Islands')
        ->and($assessment->reason)->toContain('IGIC');
});

it('covers both Canary provinces, not just the one', function (): void {
    // 35xxx is Las Palmas. Half the archipelago would otherwise be taxed as
    // mainland Spain.
    expect($this->tax->assess(delivery('ES', '35001'))->treatment)->toBe(TaxTreatment::ZeroRated);
});

it('stops charging Spanish VAT in Ceuta and Melilla', function (): void {
    // These two resolve as ordinary Spanish subdivisions with isEuMember = true,
    // so the engine did not merely fail to place them — it placed them wrongly and
    // charged 21%.
    expect($this->tax->assess(delivery('ES', '51001'))->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($this->tax->assess(delivery('ES', '52001'))->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($this->tax->assess(delivery('ES', '51001'))->reason)->toContain('IPSI');
});

it('leaves mainland Spain alone', function (): void {
    // Madrid is 28xxx. The territories are the exception, and a seam that caught
    // anything else would break every ordinary Spanish sale.
    expect($this->tax->assess(delivery('ES', '28001'))->treatment)->toBe(TaxTreatment::Standard);
});

it('recognises the single-municipality territories', function (): void {
    // Büsingen, Heligoland, Livigno and Campione d'Italia are one postcode each,
    // and are outside the VAT area for reasons that predate the Union.
    expect($this->tax->assess(delivery('DE', '78266'))->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($this->tax->assess(delivery('DE', '27498'))->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($this->tax->assess(delivery('IT', '23041'))->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($this->tax->assess(delivery('IT', '22061'))->treatment)->toBe(TaxTreatment::ZeroRated);
});

it('says in one sentence what applies in a territory outside the VAT area', function (string $country, string $postalCode, string $sentence): void {
    expect($this->tax->assess(delivery($country, $postalCode))->reason)->toEndWith('so this is an export and no EU VAT is due. '.$sentence);
})->with([
    'a tax of its own' => ['ES', '35001', 'IGIC (Impuesto General Indirecto Canario) applies there and is not computed by this engine.'],
    'a neighbour\'s VAT' => ['DE', '78266', 'Swiss VAT applies there and is not computed by this engine.'],
    'no VAT' => ['DE', '27498', 'No VAT is levied there.'],
    'no VAT, by law' => ['FR', '97600', 'No VAT is levied there (for now, CGI art. 294-1).'],
    'a collectivity' => ['FR', '98800', 'The territory’s own tax system applies there and is not computed by this engine.'],
]);

it('charges no French VAT on a delivery to the overseas territories sent as FR', function (string $postalCode, string $territory): void {
    // Guadeloupe, Martinique, French Guiana, Réunion, Mayotte and Saint-Martin are
    // outside the EU VAT area (Art. 6(1)(c)); the collectivities are outside the EU.
    // All have French postcodes, and a 97100 address sent as FR was charged 20%.
    $assessment = $this->tax->assess(delivery('FR', $postalCode));

    expect($assessment->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and((string) $assessment->tax->getAmount())->toBe('0.00')
        ->and($assessment->reason)->toContain($territory);
})->with([
    ['97100', 'Guadeloupe'],
    ['97200', 'Martinique'],
    ['97300', 'French Guiana'],
    ['97400', 'Réunion'],
    ['97600', 'Mayotte'],
    ['97150', 'Saint-Martin'],
    ['97133', 'Saint-Barthélemy'],
    ['97500', 'Saint-Pierre-et-Miquelon'],
    ['98800', 'New Caledonia'],
]);

// Guadeloupe, Martinique and Réunion: France levies its own TVA there at 8.5% and
// 2.1% on any seller (CGI art. 259 D, 296), and OSS does not reach them (296 quater).
// A French registration collects it; without one the seller is told it owes it.

function department(string $country, SellerRegistrations $seller, TaxClass $class = TaxClass::DigitalService, ?string $postalCode = null, ?string $shipFrom = null, bool $business = false): TaxQuery
{
    return new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode($country)),
        customer: $business ? CustomerType::Business : CustomerType::Consumer,
        seller: $seller,
        category: $class,
        customerTaxIdValidated: $business,
        route: new SupplyRoute(shipFrom: $shipFrom === null ? null : test()->geo->find(new CountryCode($shipFrom))),
        postalCode: $postalCode,
    );
}

it('charges a French-registered seller the department rate on a digital service', function (string $country, ?string $postalCode): void {
    $seller = new SellerRegistrations(new CountryCode('DK'), [new SellerRegistration(new CountryCode('FR'))]);
    $assessment = $this->tax->assess(department($country, $seller, postalCode: $postalCode));

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $assessment->rate?->percentage)->toBe('8.5')
        ->and((string) $assessment->tax->getAmount())->toBe('8.50')
        ->and($assessment->placeOfSupply->country->value)->toBe('FR');
})->with([
    'Guadeloupe by country' => ['GP', null],
    'Réunion by country' => ['RE', null],
    'Martinique as FR with its postcode' => ['FR', '97200'],
]);

it('tells a seller without a French registration that it owes the department VAT, OSS or not', function (): void {
    $seller = new SellerRegistrations(new CountryCode('DK'), [new SellerRegistration(new CountryCode('DK'), scheme: 'oss')]);
    $assessment = $this->tax->assess(department('GP', $seller));

    expect($assessment->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and((string) $assessment->tax->getAmount())->toBe('0.00')
        ->and($assessment->reason)->toContain('8.5% standard')
        ->and($assessment->reason)->toContain('OSS does not reach Guadeloupe');
});

it('charges the reduced department band on goods already in the department', function (): void {
    // A French book is 5.5% on the mainland; in the departments the reduced band is 2.1%.
    $seller = new SellerRegistrations(new CountryCode('FR'));
    $assessment = $this->tax->assess(department('RE', $seller, TaxClass::Book, shipFrom: 'RE'));

    expect((string) $assessment->rate?->percentage)->toBe('2.1')
        ->and((string) $assessment->tax->getAmount())->toBe('2.10');
});

it('charges no VAT on goods sent into a department, even by a French seller', function (): void {
    $assessment = $this->tax->assess(department('MQ', new SellerRegistrations(new CountryCode('FR')), TaxClass::Book, shipFrom: 'FR'));

    expect($assessment->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and((string) $assessment->tax->getAmount())->toBe('0.00')
        ->and($assessment->reason)->toContain('octroi de mer');
});

it('reverse-charges a service to a business in a department from a seller outside France', function (): void {
    $assessment = $this->tax->assess(department('GP', new SellerRegistrations(new CountryCode('DK')), business: true));

    expect($assessment->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and($assessment->reason)->toContain('283-2');
});

it('charges no VAT in French Guiana or Mayotte', function (string $country): void {
    $seller = new SellerRegistrations(new CountryCode('DK'), [new SellerRegistration(new CountryCode('FR'))]);
    $assessment = $this->tax->assess(department($country, $seller));

    expect($assessment->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and((string) $assessment->tax->getAmount())->toBe('0.00');
})->with(['GF', 'YT']);

it('keeps mainland France and Monaco French', function (): void {
    // Paris is 75001; 98000 is Monaco, which Art. 7 treats as France.
    expect($this->tax->assess(delivery('FR', '75001'))->treatment)->toBe(TaxTreatment::Standard)
        ->and($this->tax->assess(delivery('FR', '98000'))->treatment)->toBe(TaxTreatment::Standard);
});

it('places a French overseas territory named by subdivision', function (): void {
    $territories = new StaticEuTerritories(app(RegisterDataset::class));

    expect($territories->forSubdivision(new SubdivisionCode('FR-971'))?->name)->toBe('Guadeloupe')
        ->and($territories->forSubdivision(new SubdivisionCode('FR-RE'))?->outsideVatArea)->toBeTrue()
        ->and($territories->forSubdivision(new SubdivisionCode('FR-IDF')))->toBeNull();
});

it('does not catch the neighbouring postcodes of those municipalities', function (): void {
    // A single-code territory must match that code and nothing near it. Konstanz
    // is 78462 and Como 22100 — both ordinary.
    expect($this->tax->assess(delivery('DE', '78462'))->treatment)->toBe(TaxTreatment::Standard)
        ->and($this->tax->assess(delivery('IT', '22100'))->treatment)->toBe(TaxTreatment::Standard);
});

it('treats a missing postcode as unplaceable, not as mainland', function (): void {
    // The honest reading of no postcode is "we cannot tell", and the national
    // rules are what the engine applies — but it must not be because a missing
    // code was taken as proof of mainland. The seam returns null; the deduction
    // that this is Spain is the regime's, made explicitly.
    $territories = new StaticEuTerritories(app(RegisterDataset::class));

    expect($territories->for(new CountryCode('ES'), null))->toBeNull()
        ->and($territories->for(new CountryCode('ES'), ''))->toBeNull();
});

// ---- The territories that keep their own rates -------------------------------

it('identifies the Portuguese islands, which are inside the VAT area', function (): void {
    // A different case from Spain's, and the reason territory is modelled rather
    // than "special = no tax": the Azores charge 16% and Madeira 22% where the
    // mainland charges 23%. They are IN the VAT area with their own rates.
    $territories = new StaticEuTerritories(app(RegisterDataset::class));

    $madeira = $territories->for(new CountryCode('PT'), '9000-001');
    $azores = $territories->for(new CountryCode('PT'), '9500-001');

    expect($madeira?->name)->toBe('Madeira')
        ->and($madeira?->outsideVatArea)->toBeFalse()
        ->and($madeira?->standardRate)->toBe('22')
        ->and($azores?->standardRate)->toBe('16')
        ->and($territories->for(new CountryCode('PT'), '1000-001'))->toBeNull();  // Lisbon
});

it('is bound by default so a host gets this without wiring anything', function (): void {
    expect($this->app->make(EuTerritories::class))->toBeInstanceOf(StaticEuTerritories::class);
});

it('charges Madeira its own 22% rather than the mainland 23%', function (): void {
    // The other kind of territory: inside the VAT area, own rates. Identifying it
    // was only half the job — until now the rate came from Portugal.
    $assessment = $this->tax->assess(delivery('PT', '9000-001'));

    expect($assessment->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $assessment->rate?->percentage)->toBe('22')
        ->and((string) $assessment->tax->getAmount())->toBe('22.00')
        ->and($assessment->reason)->toContain('Madeira');
});

it('charges the Azores 16%, the largest gap of the three', function (): void {
    // Seven points below the mainland. Charged as Portugal, every invoice into the
    // Azores over-collects by 7%.
    expect((string) $this->tax->assess(delivery('PT', '9500-001'))->rate?->percentage)->toBe('16');
});

it('leaves mainland Portugal on its own rate', function (): void {
    // Lisbon is 1000-xxx.
    expect((string) $this->tax->assess(delivery('PT', '1000-001'))->rate?->percentage)->toBe('23');
});

it('marks the regional rate as derived, not authoritative', function (): void {
    // It comes from a shipped territory map rather than from a rate feed, and the
    // confidence should say so — an operator filtering on Authoritative is asking
    // exactly the right question.
    expect($this->tax->assess(delivery('PT', '9000-001'))->rate?->confidence)
        ->toBe(Confidence::Derived);
});

// ---- The territories' own rates, at every level --------------------------------

it('charges Madeira its own reduced rate, not the mainland band', function (): void {
    // The gap this closes. The regime substituted the STANDARD rate only, so a
    // Madeira grocery line kept mainland Portugal's 6% with a caveat saying it might
    // be two points high. It was: Madeira charges 4%.
    //
    // Rates from Ofício Circulado n.º 25045 (2024-12-06), Anexo — the Portuguese tax
    // authority's own table. CIVA art. 18 n.º 3 deliberately does not carry them; it
    // delegates to the regional assemblies, which is why reading the tax code alone
    // finds nothing.
    $territory = new StaticEuTerritories(app(RegisterDataset::class))->for(new CountryCode('PT'), '9000-001');

    expect($territory?->name)->toBe('Madeira')
        ->and($territory?->rateFor('23'))->toBe('22')
        ->and($territory?->rateFor('13'))->toBe('12')
        ->and($territory?->rateFor('6'))->toBe('4');
});

it('prices a Madeira supply with the reduced rate in force on its date', function (): void {
    // Madeira's reduced rate went from 5% to 4% on 2024-10-01 (DLR 6/2024/M art.
    // 21.º, effective under art. 121.º n.º 2). An invoice corrected afterwards must
    // reprice at what applied then.
    $before = new StaticEuTerritories(app(RegisterDataset::class))->for(new CountryCode('PT'), '9000-001', new DateTimeImmutable('2024-06-01'));
    $after = new StaticEuTerritories(app(RegisterDataset::class))->for(new CountryCode('PT'), '9000-001', new DateTimeImmutable('2024-10-01'));

    expect($before?->rateFor('6'))->toBe('5')
        ->and($after?->rateFor('6'))->toBe('4');
});

it('charges the Azores 30% below every national level', function (): void {
    // DLR 15-A/2021/A cut the national rates by 30% from 2021-07-01, turning
    // 6/13/23 into 4/9/16 — one rule, three levels, and the engine must apply it at
    // whichever level the supply lands on.
    $territory = new StaticEuTerritories(app(RegisterDataset::class))->for(new CountryCode('PT'), '9500-001');

    expect($territory?->name)->toBe('Azores')
        ->and($territory?->rateFor('23'))->toBe('16')
        ->and($territory?->rateFor('13'))->toBe('9')
        ->and($territory?->rateFor('6'))->toBe('4');
});

it('leaves a level it does not carry on the mainland band, and says so', function (): void {
    // Deny-by-default at the level lookup: an unknown mainland rate is not silently
    // mapped to the standard one.
    expect(new StaticEuTerritories(app(RegisterDataset::class))->for(new CountryCode('PT'), '9000-001')?->rateFor('99'))->toBeNull();
});

it('lets a host rebind the territory list and actually reach the regime', function (): void {
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

it('takes the islands\' rates from the register, not from code', function (): void {
    // The figures used to live in this package, copied from the register that already
    // published them. A copy is correct until Portugal changes a rate. Change it in
    // the register, and the territory follows — there is nothing here to update.
    $store = sys_get_temp_dir().'/cbox-tax-islands-'.getmypid().'-'.bin2hex(random_bytes(4));
    FakeRegister::at($store)
        ->rate('eu:PT', '23', from: '1990-01-01')->rate('eu:PT', '13', 'intermediate', from: '1990-01-01')->rate('eu:PT', '6', 'reduced', from: '1990-01-01')
        ->rate('eu:PT:MADEIRA', '21', from: '1990-01-01')->rate('eu:PT:MADEIRA', '11', 'intermediate', from: '1990-01-01')->rate('eu:PT:MADEIRA', '3', 'reduced', from: '1990-01-01')
        ->install();
    $layout = new StoreLayout($store);
    $madeira = new StaticEuTerritories(new RegisterDataset($layout, new StorePointer($layout)))->for(new CountryCode('PT'), '9000-001');

    expect($madeira?->standardRate)->toBe('21')
        ->and($madeira?->rateFor('13'))->toBe('11')
        ->and($madeira?->rateFor('6'))->toBe('3');
});

it('refuses an island supply when the register does not carry the island', function (): void {
    // Never the mainland's 23% in its place: that is a confident wrong rate on every
    // invoice into the region.
    $store = sys_get_temp_dir().'/cbox-tax-islands-'.getmypid().'-'.bin2hex(random_bytes(4));
    FakeRegister::at($store)->rate('eu:PT', '23', from: '1990-01-01')->install();
    $layout = new StoreLayout($store);

    new StaticEuTerritories(new RegisterDataset($layout, new StorePointer($layout)))->for(new CountryCode('PT'), '9500-001');
})->throws(UnresolvedTaxRule::class, 'Refusing rather than charging the mainland rate');

function spanishSale(?string $subdivision, ?string $postalCode): TaxAssessment
{
    $geo = app(JurisdictionRepository::class);
    $place = $subdivision === null ? $geo->find(new CountryCode('ES')) : $geo->find(new CountryCode('ES'), new SubdivisionCode($subdivision));

    return app(TaxCalculator::class)->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $place,
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('ES')),
        postalCode: $postalCode,
    ));
}

it('reads the Canary Islands from a subdivision when there is no postcode', function (): void {
    // An address geocoded to Santa Cruz de Tenerife, postcode absent, was priced at
    // mainland Spanish VAT and marked reliable. It is an export.
    expect(spanishSale('ES-TF', null)->treatment)->toBe(TaxTreatment::ZeroRated);
});

it('flags a Spanish sale nothing placed on the mainland', function (): void {
    // The national rate is still the best figure — most Spanish addresses are on the
    // mainland — but it is no longer called authoritative.
    expect(spanishSale(null, null)->rate?->limitedBy)->toBe(RateLimit::TerritoryUnplaced)
        ->and(spanishSale(null, '28001')->rate?->limitedBy)->toBeNull();
});
