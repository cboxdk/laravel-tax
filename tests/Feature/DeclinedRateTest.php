<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\Testing\FakeRegister;

/*
 * A rate the law names and the register could not scope. Burkina Faso taxes hotel
 * stays at 10% at APPROVED hotels — a fact about the supplier — so the register
 * declines to file it and the category resolves to the standard 18%. Right for most
 * sellers, wrong for the ones the law meant, and it has to say so.
 */
beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/declined-rates');

    FakeRegister::at(config('tax.register.store'))
        ->category('goods')->category('services')->category('services.accommodation', 'services')->category('services.accommodation.hotel', 'services.accommodation')
        ->rate('africa:BF', '18', from: '2000-01-01')
        ->rule('africa:BF', 'declined_rate', [
            'reason' => 'scope_not_typable',
            'names' => 'accommodation supplied by approved hotels',
            'category' => 'services.accommodation',
            'rate' => '10',
            'says' => 'Ce taux est réduit à 10% pour les prestations d’hébergement fournies par les hôtels agréés.',
        ], from: '2000-01-01')
        ->rule('africa:BF', 'declined_rate', [
            'reason' => 'no_category',
            'names' => 'certain basic necessities',
            'rate' => '8',
            'says' => 'Taux réduit : 8% applicable à certains produits de première nécessité.',
        ], from: '2000-01-01')
        ->install();
});

function declinedSource(): RegisterRateSource
{
    return new RegisterRateSource(app(RegisterDataset::class));
}

function burkina(): Jurisdiction
{
    return app(JurisdictionRepository::class)->find(new CountryCode('BF'));
}

it('flags the standard rate where a rate was declined for the category or one above it', function (string $key) {
    $rate = declinedSource()->rateForKey(burkina(), $key);

    expect((string) $rate?->percentage)->toBe('18')
        ->and($rate?->limitedBy)->toBe(RateLimit::RateDeclined)
        ->and($rate?->confidence)->toBe(Confidence::Derived);
})->with(['services.accommodation', 'services.accommodation.hotel']);

it('does not flag a broader question, nor a rate declined without a category', function (string $key) {
    $rate = declinedSource()->rateForKey(burkina(), $key);

    expect((string) $rate?->percentage)->toBe('18')
        ->and($rate?->limitedBy)->toBeNull()
        ->and($rate?->confidence)->toBe(Confidence::Authoritative);
})->with(['services', 'goods']);
