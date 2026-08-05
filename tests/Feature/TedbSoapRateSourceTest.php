<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\RateSource\TedbSoapRateSource;
use Illuminate\Http\Client\Factory;

// The fixture is a genuine VatRetrievalService response, captured 2026-08-05 for
// DK, FR and PL — chosen because between them they cover every case the parser
// has to get right: a country with no reduced bands at all (DK), unambiguous
// bands plus one category carried at three rates at once (FR), and a country
// where most categories are split across two rates (PL).

function tedbResponse(): string
{
    return (string) file_get_contents(dirname(__DIR__).'/Fixtures/tedb-dk-fr-pl.xml');
}

function tedbSource(Factory $http): TedbSoapRateSource
{
    return new TedbSoapRateSource($http);
}

function tedbPlace(string $country): Jurisdiction
{
    $geo = app(JurisdictionRepository::class);

    return $geo->find(new CountryCode($country)) ?? throw new RuntimeException($country.' did not resolve.');
}

beforeEach(function () {
    $this->http = new Factory;
    $this->http->fake(['ec.europa.eu/*' => $this->http->response(tedbResponse())]);
});

it('resolves the standard rate from the live service', function () {
    $rate = tedbSource($this->http)->rateFor(tedbPlace('DK'), TaxCategory::Standard);

    expect($rate)->not->toBeNull()
        ->and((string) $rate->percentage)->toBe('25')
        ->and($rate->kind)->toBe(RateKind::Standard)
        ->and($rate->source)->toBe('tedb')
        ->and($rate->confidence)->toBe(Confidence::Authoritative);
});

it('posts a SOAP envelope for the requested member state', function () {
    tedbSource($this->http)->rateFor(tedbPlace('FR'), TaxCategory::Standard);

    $this->http->assertSent(function ($request): bool {
        $body = $request->body();

        return $request->url() === TedbSoapRateSource::ENDPOINT
            && $request->hasHeader('SOAPAction')
            && str_contains($body, '<typ:isoCode>FR</typ:isoCode>')
            && str_contains($body, '<typ:situationOn>');
    });
});

it('spells Greece EL, which is what TEDB accepts', function () {
    tedbSource($this->http)->rateFor(tedbPlace('GR'), TaxCategory::Standard);

    // A GR code faults the whole request with TEDB-ERR-2, so the translation has
    // to happen before the call — not after a failure.
    $this->http->assertSent(fn ($request): bool => str_contains($request->body(), '<typ:isoCode>EL</typ:isoCode>'));
});

it('resolves a reduced band where the category carries exactly one rate', function () {
    $source = tedbSource($this->http);

    // France: books 5.5%, newspapers 2.1%, restaurant 10% — each unambiguous.
    expect($source->rateFor(tedbPlace('FR'), TaxCategory::Books)?->percentage?->__toString())->toBe('5.5')
        ->and($source->rateFor(tedbPlace('FR'), TaxCategory::Books)?->kind)->toBe(RateKind::Reduced)
        ->and($source->rateFor(tedbPlace('FR'), TaxCategory::Newspapers)?->percentage?->__toString())->toBe('2.1')
        ->and($source->rateFor(tedbPlace('FR'), TaxCategory::PreparedFood)?->percentage?->__toString())->toBe('10')
        ->and($source->rateFor(tedbPlace('FR'), TaxCategory::Grocery)?->percentage?->__toString())->toBe('5.5');
});

it('refuses a band the response carries at several rates and charges standard instead', function () {
    $source = tedbSource($this->http);

    // Poland splits foodstuffs across 5% and 8% by product type, and periodicals
    // likewise — no note resolves which one the category means, so no band is
    // curated and the standard 23% applies. Over-charging is recoverable; silently
    // picking one of the two is not.
    foreach ([TaxCategory::Grocery, TaxCategory::Magazines] as $category) {
        $rate = $source->rateFor(tedbPlace('PL'), $category);

        expect($rate?->percentage?->__toString())->toBe('23')
            ->and($rate?->kind)->toBe(RateKind::Standard);
    }
});

it('falls back to the standard rate for a category it deliberately does not map', function () {
    // Digital services are not mapped — TEDB folds e-publications into other
    // categories in some states, so a band would be a guess.
    $rate = tedbSource($this->http)->rateFor(tedbPlace('FR'), TaxCategory::DigitalService);

    expect($rate?->percentage?->__toString())->toBe('20')
        ->and($rate?->kind)->toBe(RateKind::Standard);
});

it('answers only for the EU and defers otherwise', function () {
    expect(tedbSource($this->http)->rateFor(tedbPlace('US'), TaxCategory::Standard))->toBeNull();

    $this->http->assertNothingSent();
});

it('denies by default when the service faults', function () {
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response('<env:Envelope><env:Body><env:Fault/></env:Body></env:Envelope>', 500)]);

    expect(tedbSource($http)->rateFor(tedbPlace('DK'), TaxCategory::Standard))->toBeNull();
});

it('denies by default on unparseable XML', function () {
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response('not xml at all')]);

    expect(tedbSource($http)->rateFor(tedbPlace('DK'), TaxCategory::Standard))->toBeNull();
});

it('counts an exemption as a competing rate, not as absent', function () {
    // A category carrying an exemption AND a reduced rate is split, not settled.
    // Reading only the reduced rows would charge that rate on a zero-rated supply
    // — the exact silent error this guards. Poland is used because it carries no
    // determination for books, so nothing resolves the split.
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response(<<<'XML'
        <env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body>
        <r xmlns="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types">
          <vatRateResults><memberState>PL</memberState><type>STANDARD</type>
            <rate><type>DEFAULT</type><value>23.0</value></rate></vatRateResults>
          <vatRateResults><memberState>PL</memberState><type>REDUCED</type>
            <rate><type>EXEMPTED</type><value>0.0</value></rate>
            <category><identifier>LOAN_LIBRARIES</identifier></category></vatRateResults>
          <vatRateResults><memberState>PL</memberState><type>REDUCED</type>
            <rate><type>REDUCED_RATE</type><value>9.0</value></rate>
            <category><identifier>LOAN_LIBRARIES</identifier></category></vatRateResults>
        </r></env:Body></env:Envelope>
        XML)]);

    $rate = tedbSource($http)->rateFor(tedbPlace('PL'), TaxCategory::Books);

    expect($rate?->percentage?->__toString())->toBe('23')
        ->and($rate?->kind)->toBe(RateKind::Standard);
});

it('resolves a lone exemption as a zero rate', function () {
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response(<<<'XML'
        <env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body>
        <r xmlns="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types">
          <vatRateResults><memberState>PL</memberState><type>STANDARD</type>
            <rate><type>DEFAULT</type><value>23.0</value></rate></vatRateResults>
          <vatRateResults><memberState>PL</memberState><type>REDUCED</type>
            <rate><type>EXEMPTED</type><value>0.0</value></rate>
            <category><identifier>LOAN_LIBRARIES</identifier></category></vatRateResults>
        </r></env:Body></env:Envelope>
        XML)]);

    $rate = tedbSource($http)->rateFor(tedbPlace('PL'), TaxCategory::Books);

    expect($rate?->percentage?->__toString())->toBe('0')
        ->and($rate?->kind)->toBe(RateKind::Zero);
});

it('prefers a state\'s own specific category over the broader one', function () {
    // Poland rates NEWSPAPERS at 8% while its broader books category is split
    // across 5% and 8%. The specific tier decides, so the 8% survives.
    $rate = tedbSource($this->http)->rateFor(tedbPlace('PL'), TaxCategory::Newspapers);

    expect($rate?->percentage?->__toString())->toBe('8')
        ->and($rate?->kind)->toBe(RateKind::Reduced);
});

it('caches the parsed table so one country costs one request', function () {
    $source = new TedbSoapRateSource($this->http, app('cache')->store());

    $source->rateFor(tedbPlace('FR'), TaxCategory::Standard);
    $source->rateFor(tedbPlace('FR'), TaxCategory::Books);
    $source->rateFor(tedbPlace('FR'), TaxCategory::Newspapers);

    $this->http->assertSentCount(1);
});

it('applies a determination where TEDB splits a category', function () {
    // FR pharmaceuticals sit at 2.1%, 5.5% and 10% at once. TEDB's own note says
    // 2.1% is "reimbursed pharmaceutical products", 10% non-reimbursed and 5.5%
    // sanitary protection — so a prescribed medicine is the 2.1% band.
    $rate = tedbSource($this->http)->rateFor(tedbPlace('FR'), TaxCategory::PrescriptionDrugs);

    expect($rate?->percentage?->__toString())->toBe('2.1')
        ->and($rate?->kind)->toBe(RateKind::Reduced);
});

it('leaves a category with no determination at the standard rate', function () {
    // Poland splits foodstuffs 5%/8% by product type, and no note resolves which
    // one "grocery" means — so nothing is curated and the standard rate applies.
    expect(tedbSource($this->http)->rateFor(tedbPlace('PL'), TaxCategory::Grocery)?->percentage?->__toString())
        ->toBe('23');
});

it('drops a determination once TEDB no longer carries its rate', function () {
    // The curated French figure is 2.1%. Here TEDB reports the category split
    // across 6% and 10% only — the law moved — so the determination is stale and
    // is refused rather than applied, and the standard rate stands.
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response(<<<'XML'
        <env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body>
        <r xmlns="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types">
          <vatRateResults><memberState>FR</memberState><type>STANDARD</type>
            <rate><type>DEFAULT</type><value>20.0</value></rate></vatRateResults>
          <vatRateResults><memberState>FR</memberState><type>REDUCED</type>
            <rate><type>REDUCED_RATE</type><value>6.0</value></rate>
            <category><identifier>PHARMACEUTICAL_PRODUCTS</identifier></category></vatRateResults>
          <vatRateResults><memberState>FR</memberState><type>REDUCED</type>
            <rate><type>REDUCED_RATE</type><value>10.0</value></rate>
            <category><identifier>PHARMACEUTICAL_PRODUCTS</identifier></category></vatRateResults>
        </r></env:Body></env:Envelope>
        XML)]);

    $rate = tedbSource($http)->rateFor(tedbPlace('FR'), TaxCategory::PrescriptionDrugs);

    expect($rate?->percentage?->__toString())->toBe('20')
        ->and($rate?->kind)->toBe(RateKind::Standard);
});

it('never overrides an unambiguous TEDB answer', function () {
    // Ireland's books are curated to 0%. Where TEDB reports one rate, that rate
    // wins — a determination only ever resolves a split.
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response(<<<'XML'
        <env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body>
        <r xmlns="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types">
          <vatRateResults><memberState>IE</memberState><type>STANDARD</type>
            <rate><type>DEFAULT</type><value>23.0</value></rate></vatRateResults>
          <vatRateResults><memberState>IE</memberState><type>REDUCED</type>
            <rate><type>REDUCED_RATE</type><value>4.0</value></rate>
            <category><identifier>LOAN_LIBRARIES</identifier></category></vatRateResults>
        </r></env:Body></env:Envelope>
        XML)]);

    expect(tedbSource($http)->rateFor(tedbPlace('IE'), TaxCategory::Books)?->percentage?->__toString())
        ->toBe('4');
});
