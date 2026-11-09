<?php

declare(strict_types=1);

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Tax\TaxServiceProvider;
use Cbox\Tax\Testing\FakeVatIdValidator;
use Cbox\Tax\Validators\AbnLookupValidator;
use Cbox\Tax\Validators\DispatchingVatIdValidator;
use Cbox\Tax\Validators\HmrcVatValidator;
use Cbox\Tax\Validators\RegistryBackoff;
use Cbox\Tax\Validators\ViesValidator;
use Cbox\Tax\ValueObjects\VatIdValidation;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

it('validates a valid EU VAT number via VIES and records the consultation reference', function (): void {
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response([
        'valid' => true, 'name' => 'ACME GmbH', 'address' => 'Berlin', 'requestIdentifier' => 'WAPIAAAA',
    ])]);

    $r = new ViesValidator($http)->validate(new CountryCode('DE'), 'DE123456789');

    expect($r->permitsReverseCharge())->toBeTrue()
        ->and($r->name)->toBe('ACME GmbH')
        ->and($r->consultationReference)->toBe('WAPIAAAA');
});

it('conclusively rejects an invalid EU VAT number', function (): void {
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response(['valid' => false])]);

    $r = new ViesValidator($http)->validate(new CountryCode('DE'), '000');

    expect($r->valid)->toBeFalse()
        ->and($r->conclusive)->toBeTrue()
        ->and($r->permitsReverseCharge())->toBeFalse();
});

it('is fail-safe (inconclusive, no reverse charge) when VIES is unavailable', function (): void {
    $http = new Factory;
    $http->fake(['ec.europa.eu/*' => $http->response('', 503)]);

    $r = new ViesValidator($http)->validate(new CountryCode('FR'), 'FR123');

    expect($r->conclusive)->toBeFalse()
        ->and($r->permitsReverseCharge())->toBeFalse();
});

it('supports Greece under VIES', function (): void {
    expect(new ViesValidator(new Factory)->supports(new CountryCode('GR')))->toBeTrue();
});

it('validates a UK VAT number via HMRC', function (): void {
    $http = new Factory;
    // The shape HMRC actually returns: the looked-up number echoed back beside
    // the registered name and address.
    $http->fake(['api.service.hmrc.gov.uk/*' => $http->response([
        'target' => [
            'name' => 'ACME Ltd',
            'vatNumber' => '123456789',
            'address' => ['line1' => '1 High Street', 'postcode' => 'SW1A 1AA', 'countryCode' => 'GB'],
        ],
        'consultationNumber' => 'ABC123',
    ])]);

    $r = new HmrcVatValidator($http)->validate(new CountryCode('GB'), 'GB123456789');

    expect($r->permitsReverseCharge())->toBeTrue()
        ->and($r->name)->toBe('ACME Ltd')
        ->and($r->address)->toBe('1 High Street, SW1A 1AA, GB')
        ->and($r->consultationReference)->toBe('ABC123');
});

it('does not treat a bare 2xx from HMRC as proof of registration', function (): void {
    // An empty body, an error envelope, or a captive portal serving JSON all
    // arrive as a successful response. None of them says the number is
    // registered, and reading them as "conclusively valid" zero-rates a UK B2B
    // supply that was never checked.
    $bodies = [
        'empty object' => [],
        'error envelope' => ['code' => 'INVALID_REQUEST', 'message' => 'nope'],
        'target without a number' => ['target' => ['name' => 'ACME Ltd']],
        'target without a name' => ['target' => ['vatNumber' => '123456789']],
    ];

    foreach ($bodies as $label => $body) {
        $http = new Factory;
        $http->fake(['api.service.hmrc.gov.uk/*' => $http->response($body)]);

        $r = new HmrcVatValidator($http)->validate(new CountryCode('GB'), 'GB123456789');

        expect($r->permitsReverseCharge())->toBeFalse("$label must not permit reverse charge")
            ->and($r->conclusive)->toBeFalse("$label must be inconclusive");
    }
});

it('refuses an HMRC response about a different registration', function (): void {
    $http = new Factory;
    $http->fake(['api.service.hmrc.gov.uk/*' => $http->response([
        'target' => ['name' => 'Someone Else Ltd', 'vatNumber' => '999999999'],
    ])]);

    $r = new HmrcVatValidator($http)->validate(new CountryCode('GB'), 'GB123456789');

    expect($r->permitsReverseCharge())->toBeFalse()
        ->and($r->conclusive)->toBeFalse();
});

it('treats an HMRC 404 as conclusively not registered', function (): void {
    $http = new Factory;
    $http->fake(['api.service.hmrc.gov.uk/*' => $http->response('', 404)]);

    $r = new HmrcVatValidator($http)->validate(new CountryCode('GB'), 'GB000');

    expect($r->valid)->toBeFalse()->and($r->conclusive)->toBeTrue();
});

it('dispatches to the right validator by country and is inconclusive for unsupported ones', function (): void {
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

it('provides a configurable fake for tests', function (): void {
    $fake = (new FakeVatIdValidator)->willReturn(new CountryCode('DE'), 'DE1', VatIdValidation::valid('fake'));

    expect($fake->validate(new CountryCode('DE'), 'DE1')->permitsReverseCharge())->toBeTrue()
        ->and($fake->validate(new CountryCode('DE'), 'OTHER')->conclusive)->toBeFalse();
});

it('gives every registry call a short connect and request timeout from config', function (): void {
    config()->set('tax.vat_id.abn_guid', 'guid');
    config()->set('tax.vat_id.timeout', '4.5');
    config()->set('tax.vat_id.connect_timeout', 2);
    new TaxServiceProvider(app())->register();

    $http = app(Factory::class);
    $http->preventStrayRequests();
    $seen = [];
    $http->fake(function ($request, array $options) use ($http, &$seen) {
        $seen[] = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

        return $http->response('', 503);
    });

    $validator = app(VatIdValidator::class);

    foreach (['DE' => 'DE123', 'GB' => 'GB123', 'AU' => '51824753556'] as $country => $id) {
        $validator->validate(new CountryCode($country), $id);
    }

    expect($seen)->toBe([[4.5, 2.0], [4.5, 2.0], [4.5, 2.0]]);
});

it('defaults registry timeouts to 6 s and 3 s when config predates the keys', function (): void {
    config()->set('tax.vat_id', []);
    new TaxServiceProvider(app())->register();

    $http = app(Factory::class);
    $seen = [];
    $http->fake(function ($request, array $options) use ($http, &$seen) {
        $seen = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

        return $http->response(['valid' => false]);
    });

    app(VatIdValidator::class)->validate(new CountryCode('DE'), 'DE123');

    expect($seen)->toBe([6.0, 3.0]);
});

it('answers inconclusive, never throws, when a registry times out', function (string $country, string $id): void {
    $http = new Factory;
    $http->fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $validators = [new ViesValidator($http), new HmrcVatValidator($http), new AbnLookupValidator($http, 'guid')];

    $r = new DispatchingVatIdValidator($validators)->validate(new CountryCode($country), $id);

    expect($r->conclusive)->toBeFalse()
        ->and($r->permitsReverseCharge())->toBeFalse();
})->with([
    'VIES' => ['DE', 'DE123456789'],
    'HMRC' => ['GB', 'GB123456789'],
    'ABN' => ['AU', '51824753556'],
]);

it('backs off a registry for a country after an outage instead of waiting on it again', function (): void {
    $http = new Factory;
    $calls = 0;
    $http->fake(function () use (&$calls): void {
        $calls++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $vies = new ViesValidator($http, backoff: new RegistryBackoff(new Repository(new ArrayStore), 60));

    $first = $vies->validate(new CountryCode('DE'), 'DE123456789');
    $second = $vies->validate(new CountryCode('DE'), 'DE987654321');

    expect($first->conclusive)->toBeFalse()
        ->and($second->conclusive)->toBeFalse()
        ->and($second->source)->toBe('vies')
        ->and($calls)->toBe(1);

    // VIES fails one member state at a time: France is still asked.
    $vies->validate(new CountryCode('FR'), 'FR123');

    expect($calls)->toBe(2);
});

it('asks the registry again once the backoff window has passed', function (): void {
    $http = new Factory;
    $responses = [$http->response('', 503), $http->response(['valid' => true, 'name' => 'ACME'])];
    $http->fake(function () use (&$responses): ?PromiseInterface {
        return array_shift($responses);
    });

    $vies = new ViesValidator($http, backoff: new RegistryBackoff(new Repository(new ArrayStore), 60));

    expect($vies->validate(new CountryCode('DE'), 'DE123')->conclusive)->toBeFalse();

    $this->travel(61)->seconds();

    expect($vies->validate(new CountryCode('DE'), 'DE123')->permitsReverseCharge())->toBeTrue();
});

it('trips the backoff on 5xx and 429 but never on an answer', function (): void {
    $http = new Factory;
    $status = 404;
    $http->fake(function () use ($http, &$status) {
        return $http->response('', $status);
    });

    $backoff = new RegistryBackoff(new Repository(new ArrayStore), 60);
    $hmrc = new HmrcVatValidator($http, backoff: $backoff);
    $gb = new CountryCode('GB');

    // A 404 is HMRC's conclusive "not registered" — an answer, so no backoff, and
    // its meaning is unchanged.
    $r = $hmrc->validate($gb, 'GB000');
    expect($r->valid)->toBeFalse()->and($r->conclusive)->toBeTrue()
        ->and($backoff->active('hmrc', $gb))->toBeFalse();

    $status = 429;
    $hmrc->validate($gb, 'GB000');
    expect($backoff->active('hmrc', $gb))->toBeTrue();
});

it('disables the backoff at zero seconds and survives a broken cache', function (): void {
    $de = new CountryCode('DE');

    $off = new RegistryBackoff(new Repository(new ArrayStore), 0);
    $off->trip('vies', $de);
    expect($off->active('vies', $de))->toBeFalse();

    $broken = Mockery::mock(CacheRepository::class);
    $broken->shouldReceive('has')->andThrow(new RuntimeException('cache down'));
    $broken->shouldReceive('put')->andThrow(new RuntimeException('cache down'));

    $backoff = new RegistryBackoff($broken, 60);
    $backoff->trip('vies', $de);
    expect($backoff->active('vies', $de))->toBeFalse();
});

it('honours the configured backoff window and store', function (): void {
    config()->set('tax.vat_id.unavailable_backoff', 0);
    new TaxServiceProvider(app())->register();

    $http = app(Factory::class);
    $calls = 0;
    $http->fake(function () use ($http, &$calls) {
        $calls++;

        return $http->response('', 503);
    });

    app(VatIdValidator::class)->validate(new CountryCode('DE'), 'DE1');
    app(VatIdValidator::class)->validate(new CountryCode('DE'), 'DE1');

    expect($calls)->toBe(2);

    config()->set('tax.vat_id.unavailable_backoff', 60);
    new TaxServiceProvider(app())->register();
    app()->forgetInstance(VatIdValidator::class);
    $calls = 0;

    app(VatIdValidator::class)->validate(new CountryCode('DE'), 'DE1');
    app(VatIdValidator::class)->validate(new CountryCode('DE'), 'DE1');

    expect($calls)->toBe(1);
});
