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

    // FR pharmaceuticals sit at 2.1%, 5.5% AND 10% — sub-scopes the response does
    // not resolve. Charging the standard 20% is recoverable; silently picking 2.1%
    // is not.
    $rate = $source->rateFor(tedbPlace('FR'), TaxCategory::PrescriptionDrugs);

    expect($rate?->percentage?->__toString())->toBe('20')
        ->and($rate?->kind)->toBe(RateKind::Standard);

    // Poland splits foodstuffs across 5% and 8%.
    $polish = $source->rateFor(tedbPlace('PL'), TaxCategory::Grocery);

    expect($polish?->percentage?->__toString())->toBe('23')
        ->and($polish?->kind)->toBe(RateKind::Standard);
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
    // Ireland files printed books as EXEMPTED 0% and their electronic form at
    // REDUCED 9% under the same category. Reading only the reduced rows would
    // have charged 9% on a zero-rated book — the exact silent error this guards.
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response(<<<'XML'
        <env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body>
        <r xmlns="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types">
          <vatRateResults><memberState>IE</memberState><type>STANDARD</type>
            <rate><type>DEFAULT</type><value>23.0</value></rate></vatRateResults>
          <vatRateResults><memberState>IE</memberState><type>REDUCED</type>
            <rate><type>EXEMPTED</type><value>0.0</value></rate>
            <category><identifier>LOAN_LIBRARIES</identifier></category></vatRateResults>
          <vatRateResults><memberState>IE</memberState><type>REDUCED</type>
            <rate><type>REDUCED_RATE</type><value>9.0</value></rate>
            <category><identifier>LOAN_LIBRARIES</identifier></category></vatRateResults>
        </r></env:Body></env:Envelope>
        XML)]);

    $rate = tedbSource($http)->rateFor(tedbPlace('IE'), TaxCategory::Books);

    expect($rate?->percentage?->__toString())->toBe('23')
        ->and($rate?->kind)->toBe(RateKind::Standard);
});

it('resolves a lone exemption as a zero rate', function () {
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response(<<<'XML'
        <env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body>
        <r xmlns="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types">
          <vatRateResults><memberState>IE</memberState><type>STANDARD</type>
            <rate><type>DEFAULT</type><value>23.0</value></rate></vatRateResults>
          <vatRateResults><memberState>IE</memberState><type>REDUCED</type>
            <rate><type>EXEMPTED</type><value>0.0</value></rate>
            <category><identifier>LOAN_LIBRARIES</identifier></category></vatRateResults>
        </r></env:Body></env:Envelope>
        XML)]);

    $rate = tedbSource($http)->rateFor(tedbPlace('IE'), TaxCategory::Books);

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
