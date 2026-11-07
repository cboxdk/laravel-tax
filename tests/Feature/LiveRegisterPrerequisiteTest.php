<?php

declare(strict_types=1);

use Cbox\Tax\Exceptions\RateSourceUnavailable;
use Cbox\Tax\Register\Compile\SectionFetcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Env;
use PHPUnit\Framework\SkippedWithMessageException;

it('does not use the fixture token when live acceptance has no credential', function (): void {
    $environment = Env::getRepository();
    $original = $environment->get('TAX_REGISTER_TOKEN');
    $environment->clear('TAX_REGISTER_TOKEN');
    $http = app(Factory::class);
    $http->preventStrayRequests();
    config()->set('tax.register.token', 'fixture-register-token');

    try {
        expect(fn () => $this->requireLiveRegisterCredentials())->toThrow(SkippedWithMessageException::class);
        $http->assertNothingSent();
    } finally {
        if ($original !== null) {
            $environment->set('TAX_REGISTER_TOKEN', $original);
        }
    }
});

it('uses an explicitly configured credential and does not skip a refused response', function (): void {
    $environment = Env::getRepository();
    $original = $environment->get('TAX_REGISTER_TOKEN');
    $environment->clear('TAX_REGISTER_TOKEN');
    $environment->set('TAX_REGISTER_TOKEN', 'configured-test-credential');
    $http = app(Factory::class);
    $http->fake(['data.cboxtax.com/*' => $http->response('', 401)]);

    try {
        $this->requireLiveRegisterCredentials();
        expect(config('tax.register.token'))->toBe('configured-test-credential');
        expect(fn (): array => app(SectionFetcher::class)->json('/api/v1/releases/latest'))
            ->toThrow(RateSourceUnavailable::class);
        $http->assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer configured-test-credential'));
    } finally {
        $environment->clear('TAX_REGISTER_TOKEN');
        if ($original !== null) {
            $environment->set('TAX_REGISTER_TOKEN', $original);
        }
    }
});
