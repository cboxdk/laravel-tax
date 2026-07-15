<?php

declare(strict_types=1);

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Testing\FakeVatIdValidator;
use Cbox\Tax\Validators\DispatchingVatIdValidator;
use Cbox\Tax\Validators\HmrcVatValidator;
use Cbox\Tax\Validators\ViesValidator;
use Cbox\Tax\ValueObjects\VatIdValidation;
use Illuminate\Http\Client\Factory;

it('validates a valid EU VAT number via VIES and records the consultation reference', function () {
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response([
        'valid' => true, 'name' => 'ACME GmbH', 'address' => 'Berlin', 'requestIdentifier' => 'WAPIAAAA',
    ])]);

    $r = (new ViesValidator($http))->validate(new CountryCode('DE'), 'DE123456789');

    expect($r->permitsReverseCharge())->toBeTrue()
        ->and($r->name)->toBe('ACME GmbH')
        ->and($r->consultationReference)->toBe('WAPIAAAA');
});

it('conclusively rejects an invalid EU VAT number', function () {
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response(['valid' => false])]);

    $r = (new ViesValidator($http))->validate(new CountryCode('DE'), '000');

    expect($r->valid)->toBeFalse()
        ->and($r->conclusive)->toBeTrue()
        ->and($r->permitsReverseCharge())->toBeFalse();
});

it('is fail-safe (inconclusive, no reverse charge) when VIES is unavailable', function () {
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response('', 503)]);

    $r = (new ViesValidator($http))->validate(new CountryCode('FR'), 'FR123');

    expect($r->conclusive)->toBeFalse()
        ->and($r->permitsReverseCharge())->toBeFalse();
});

it('supports Greece under VIES', function () {
    expect((new ViesValidator(new Factory))->supports(new CountryCode('GR')))->toBeTrue();
});

it('validates a UK VAT number via HMRC', function () {
    $http = new Factory;
    $http->fake(['api.service.hmrc.gov.uk/*' => $http->response([
        'target' => ['name' => 'ACME Ltd'], 'consultationNumber' => 'ABC123',
    ])]);

    $r = (new HmrcVatValidator($http))->validate(new CountryCode('GB'), 'GB123456789');

    expect($r->permitsReverseCharge())->toBeTrue()
        ->and($r->name)->toBe('ACME Ltd')
        ->and($r->consultationReference)->toBe('ABC123');
});

it('treats an HMRC 404 as conclusively not registered', function () {
    $http = new Factory;
    $http->fake(['api.service.hmrc.gov.uk/*' => $http->response('', 404)]);

    $r = (new HmrcVatValidator($http))->validate(new CountryCode('GB'), 'GB000');

    expect($r->valid)->toBeFalse()->and($r->conclusive)->toBeTrue();
});

it('dispatches to the right validator by country and is inconclusive for unsupported ones', function () {
    $http = new Factory;
    $http->fake([
        'ec.europa.eu/*' => $http->response(['valid' => true]),
        'api.service.hmrc.gov.uk/*' => $http->response(['target' => ['name' => 'X']]),
    ]);

    $d = new DispatchingVatIdValidator([new ViesValidator($http), new HmrcVatValidator($http)]);

    expect($d->validate(new CountryCode('DE'), 'DE1')->source)->toBe('vies')
        ->and($d->validate(new CountryCode('GB'), 'GB1')->source)->toBe('hmrc')
        ->and($d->validate(new CountryCode('US'), '1')->conclusive)->toBeFalse();
});

it('provides a configurable fake for tests', function () {
    $fake = (new FakeVatIdValidator)->willReturn(new CountryCode('DE'), 'DE1', VatIdValidation::valid('fake'));

    expect($fake->validate(new CountryCode('DE'), 'DE1')->permitsReverseCharge())->toBeTrue()
        ->and($fake->validate(new CountryCode('DE'), 'OTHER')->conclusive)->toBeFalse();
});
