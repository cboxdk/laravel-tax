<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\RateSource\TedbSoapRateSource;
use Illuminate\Http\Client\Factory;

// A REAL response from the Commission's live VatRetrievalService, captured
// 2026-08-13 with the exact envelope TedbSoapRateSource sends. Every other test
// for this adapter is written against a hand-built fake, which proves the parser
// understands our own fixtures and nothing about whether it understands TEDB.
//
// Re-capture with:
//   curl -X POST https://ec.europa.eu/taxation_customs/tedb/ws/ \
//     -H 'Content-Type: text/xml;charset=UTF-8' \
//     -H 'SOAPAction: urn:ec.europa.eu:taxud:tedb:services:v1:VatRetrievalService/RetrieveVatRates' \
//     --data-binary @envelope.xml

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);

    $this->http = new Factory;
    $this->http->fake([
        'ec.europa.eu/*' => $this->http->response(
            (string) file_get_contents(dirname(__DIR__).'/Fixtures/tedb-live-dk.xml'),
            200,
            ['Content-Type' => 'text/xml'],
        ),
    ]);
});

it('parses the live service response, not just our own fixtures', function () {
    $rate = new TedbSoapRateSource($this->http)
        ->rateFor($this->geo->find(new CountryCode('DK')), TaxClass::GeneralGoods);

    // Denmark has levied a flat 25% since 1992 with no reduced band, which is why
    // it is a good canary: the standard rate must come through and nothing must
    // be mistaken for a reduced one.
    expect((string) $rate?->percentage)->toBe('25')
        ->and($rate?->kind)->toBe(RateKind::Standard)
        ->and($rate?->confidence)->toBe(Confidence::Authoritative)
        ->and($rate?->source)->toBe('tedb');
});

it('does not invent a reduced band from a country that has none', function () {
    // The live response carries EXEMPTED rows at 0.0 alongside the standard rate.
    // Reading one of those as a reduced band would zero-rate Danish books.
    $source = new TedbSoapRateSource($this->http);
    $denmark = $this->geo->find(new CountryCode('DK'));

    foreach ([TaxClass::Book, TaxClass::Groceries, TaxClass::DigitalService] as $category) {
        $rate = $source->rateFor($denmark, $category);

        expect((string) $rate?->percentage)->toBe('25', $category->value.' must fall back to the standard rate');
    }
});
