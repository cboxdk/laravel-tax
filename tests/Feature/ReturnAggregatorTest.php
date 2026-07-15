<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
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
