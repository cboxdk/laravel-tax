<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\LocalityScheme;
use Cbox\Tax\Enums\RateLimit;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\RateSource\DefersLocalAuthorities;
use Cbox\Tax\Register\Reader\RateResolver;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterBoundaries;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\Testing\FakeLocalAuthorityResolver;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->dataset = app(RegisterDataset::class);
    $this->resolver = new FakeLocalAuthorityResolver;
    $this->source = new RegisterRateSource($this->dataset, new RateResolver, $this->resolver);
});

function resolverPlace(string $state): Jurisdiction
{
    return test()->geo->find(new CountryCode('US'), new SubdivisionCode($state));
}

it('defers by default, so an app that binds nothing is unchanged', function () {
    $shipped = app(TaxRateSource::class);

    // Kansas with no locality: the state share, exactly as before the seam existed.
    expect((string) $shipped->rateFor(resolverPlace('US-KS'), TaxClass::GeneralGoods)?->percentage)->toBe('6.5');
    expect(new DefersLocalAuthorities()->authoritiesFor(resolverPlace('US-KS')))->toBeNull();
});

it('stacks every authority a host resolver returns', function () {
    // The Colorado shape: several authorities on one address, and no locality on
    // the jurisdiction at all — nothing shipped resolves that state below the
    // state line, which is exactly why a host would bind a resolver.
    $place = resolverPlace('US-KS');
    $this->resolver->resolve($place, ['us:KS', 'us:KS:COUNTY-209', 'us:KS:CITY-36000']);

    $rate = $this->source->rateFor($place, TaxClass::GeneralGoods);

    // 6.5% state + 1.0% county + 1.625% city.
    expect((string) $rate?->percentage)->toBe('9.125')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative)
        ->and($rate?->components)->toHaveCount(3)
        ->and($rate?->components[0]->level)->toBe(JurisdictionLevel::State);
});

it('is consulted even when the jurisdiction carries no locality', function () {
    $place = resolverPlace('US-KS');
    $this->resolver->resolve($place, []);

    $this->source->rateFor($place, TaxClass::GeneralGoods);

    // The failure this guards: a resolver that is bound but never reached. The
    // assessment still comes out with a plausible number — the state share — and
    // nothing in the result says the lookup never happened.
    expect($this->resolver->wasConsultedFor($place))->toBeTrue();
});

it('treats an empty list as a positive "no local authority taxes here"', function () {
    $place = resolverPlace('US-KS');
    $this->resolver->resolve($place, []);

    $rate = $this->source->rateFor($place, TaxClass::GeneralGoods);

    // Authoritative, not Derived: the state share IS the whole rate here, which is
    // a different claim from "we only managed to find the state share".
    expect((string) $rate?->percentage)->toBe('6.5')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative);
});

it('falls back to the honest state rate when the resolver defers on an address', function () {
    // WITH an address, because that is what makes the gap a gap. A caller who
    // supplied only a state got the answer they asked for and gets no caveat; one
    // who supplied an address asked to be taken below the state line and was not.
    $place = resolverPlace('US-KS')->withLocality(
        new LocalityCode(new SubdivisionCode('US-KS'), LocalityScheme::Zip9->value, '99999-0000'),
    );

    $rate = $this->source->rateFor($place, TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('6.5')
        ->and($rate?->confidence)->toBe(Confidence::Derived)
        ->and($rate?->limitedBy)->toBe(RateLimit::NoLocalResolution);

    // And without one, no caveat at all.
    expect($this->source->rateFor(resolverPlace('US-KS'), TaxClass::GeneralGoods)?->limitedBy)->toBeNull();
});

it('refuses the whole stack when one authority is not in the dataset', function () {
    $place = resolverPlace('US-KS');
    $place = $place->withLocality(
        new LocalityCode(new SubdivisionCode('US-KS'), LocalityScheme::Zip9->value, '66101-3064'),
    );
    $this->resolver->resolve($place, ['us:KS', 'us:KS:COUNTY-209', 'us:KS:COUNTY-NOT-A-CODE']);

    $rate = $this->source->rateFor($place, TaxClass::GeneralGoods);

    // Summing what resolved would be short by the missing authority's share and
    // still be stamped Authoritative — an under-charge that looks certain. The
    // state rate at Derived is the honest answer instead.
    expect((string) $rate?->percentage)->toBe('6.5')
        ->and($rate?->confidence)->toBe(Confidence::Derived);
});

it('passes the supply date through, not today', function () {
    $place = resolverPlace('US-KS');
    $this->resolver->resolve($place, []);

    $this->source->rateFor($place, TaxClass::GeneralGoods, new DateTimeImmutable('2024-03-15'));

    // An address changes hands between districts, so a backdated credit note needs
    // the authorities that applied then. A resolver handed "today" would price it
    // against a boundary that may not have existed.
    expect($this->resolver->calls[0]['at'])->toBe('2024-03-15');
});

it('wins over the shipped resolution where both could answer', function () {
    $place = resolverPlace('US-KS')->withLocality(
        new LocalityCode(
            new SubdivisionCode('US-KS'),
            LocalityScheme::Zip9->value,
            '66101-3064',
        ),
    );

    // The ZIP+4 index would return the county AND the city; the host says county
    // only. Binding a resolver is a deliberate act, so the host's answer stands.
    $this->resolver->resolve($place, ['us:KS', 'us:KS:COUNTY-209']);

    $rate = $this->source->rateFor($place, TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('7.5')
        ->and($rate?->components)->toHaveCount(2);
});

it('is bound to the register\'s own boundary resolver, which defers where it holds nothing', function () {
    // The package DOES ship local resolution now — twenty-four Streamlined states by
    // ZIP+4, California by polygon, and four more by county name. What it will not do
    // is guess: a state the register carries no artifact for defers, which sends the
    // engine to the state rate exactly as the old no-op default did.
    $bound = $this->app->make(LocalAuthorityResolver::class);

    expect($bound)->toBeInstanceOf(RegisterBoundaries::class)
        ->and($bound->authoritiesFor(resolverPlace('US-CO')))->toBeNull()
        ->and(new DefersLocalAuthorities()->authoritiesFor(resolverPlace('US-KS')))->toBeNull();
});

it('lets a host rebind it and reach the rate source through the container', function () {
    $fake = new FakeLocalAuthorityResolver;
    $fake->resolve(resolverPlace('US-KS'), ['us:KS', 'us:KS:COUNTY-209']);

    $this->app->instance(LocalAuthorityResolver::class, $fake);

    $rate = $this->app->make(TaxRateSource::class)
        ->rateFor(resolverPlace('US-KS'), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('7.5');
});
