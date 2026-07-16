<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxQuery;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
    $this->aggregator = $this->app->make(ReturnAggregator::class);
});

function dkSupply(float|string $amount): TaxQuery
{
    return new TaxQuery(
        amount: Money::of($amount, 'EUR'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
    );
}

it('sums net and tax per jurisdiction and currency', function () {
    $assessments = [
        $this->tax->assess(dkSupply('100.00')), // DK 25% -> net 100, tax 25
        $this->tax->assess(dkSupply('200.00')), // DK 25% -> net 200, tax 50
    ];

    $return = $this->aggregator->aggregate($assessments);
    $line = $return->lineFor(new CountryCode('DK'), 'EUR');

    expect($line)->not->toBeNull()
        ->and((string) $line->net->getAmount())->toBe('300.00')
        ->and((string) $line->tax->getAmount())->toBe('75.00')
        ->and($line->count)->toBe(2);
});

it('keeps different jurisdictions on separate lines', function () {
    $gb = new TaxQuery(
        amount: Money::of('100.00', 'GBP'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('GB')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('GB')),
    );

    $return = $this->aggregator->aggregate([
        $this->tax->assess(dkSupply('100.00')),
        $this->tax->assess($gb),
    ]);

    expect($return->lines)->toHaveCount(2)
        ->and((string) $return->lineFor(new CountryCode('GB'), 'GBP')->tax->getAmount())->toBe('20.00');
});

it('produces a per-state line for a mixed multi-state US set', function () {
    $us = fn (string $state) => new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('US'), new SubdivisionCode($state)),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-CA')),
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-NY')),
        ]),
    );

    $return = $this->aggregator->aggregate([
        $this->tax->assess($us('US-CA')), // CA 7.25%
        $this->tax->assess($us('US-CA')), // CA 7.25%
        $this->tax->assess($us('US-NY')), // NY 4%
    ]);

    $ca = $return->lineFor(new CountryCode('US'), 'USD', new SubdivisionCode('US-CA'));
    $ny = $return->lineFor(new CountryCode('US'), 'USD', new SubdivisionCode('US-NY'));

    expect($return->lines)->toHaveCount(2)
        ->and($ca->count)->toBe(2)
        ->and((string) $ca->net->getAmount())->toBe('200.00')
        ->and((string) $ca->tax->getAmount())->toBe('14.50')
        ->and($ny->count)->toBe(1)
        ->and((string) $ny->tax->getAmount())->toBe('4.00')
        // A bare-country lookup must NOT collapse the states into one line.
        ->and($return->lineFor(new CountryCode('US'), 'USD'))->toBeNull();
});

it('keeps each EU member state on its own line for an OSS-style set', function () {
    $euB2c = fn (string $country) => new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode($country)),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('IE')), // established elsewhere -> destination
    );

    $return = $this->aggregator->aggregate([
        $this->tax->assess($euB2c('FR')), // FR 20%
        $this->tax->assess($euB2c('DE')), // DE 19%
        $this->tax->assess($euB2c('DE')), // DE 19%
    ]);

    $fr = $return->lineFor(new CountryCode('FR'), 'EUR');
    $de = $return->lineFor(new CountryCode('DE'), 'EUR');

    expect($return->lines)->toHaveCount(2)
        ->and((string) $fr->tax->getAmount())->toBe('20.00')
        ->and($de->count)->toBe(2)
        ->and((string) $de->tax->getAmount())->toBe('38.00');
});
