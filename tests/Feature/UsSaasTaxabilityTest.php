<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\Exceptions\UnresolvedProductTaxability;
use Cbox\Tax\Taxability\StaticProductTaxability;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->taxability = new StaticProductTaxability(StaticProductTaxability::unitedStatesSaas());
});

function usPlace(string $state)
{
    return test()->geo->find(new CountryCode('US'), new SubdivisionCode($state));
}

it('treats SaaS as taxable in states that clearly tax it', function (string $state) {
    expect($this->taxability->isTaxable(usPlace($state), TaxCategory::DigitalService))->toBeTrue();
})->with(['US-NY', 'US-WA', 'US-PA', 'US-TN', 'US-CT', 'US-DC', 'US-MA']);

it('treats SaaS as exempt in states that clearly exempt it', function (string $state) {
    expect($this->taxability->isTaxable(usPlace($state), TaxCategory::DigitalService))->toBeFalse();
})->with(['US-CA', 'US-FL', 'US-GA', 'US-VA', 'US-MN', 'US-NJ']);

it('leaves undetermined states absent and refuses to guess', function (string $state) {
    $overrides = StaticProductTaxability::unitedStatesSaas();
    expect($overrides)->not->toHaveKey($state.':'.TaxCategory::DigitalService->value);

    $this->taxability->isTaxable(usPlace($state), TaxCategory::DigitalService);
})->throws(UnresolvedProductTaxability::class)
    ->with(['US-AL', 'US-MS', 'US-TX', 'US-IA', 'US-OH', 'US-MD', 'US-AK']);

it('treats no-general-sales-tax states as exempt at state level', function (string $state) {
    expect($this->taxability->isTaxable(usPlace($state), TaxCategory::DigitalService))->toBeFalse();
})->with(['US-DE', 'US-MT', 'US-NH', 'US-OR']);

it('does not touch tangible-goods (standard) taxability', function () {
    // The SaaS map only overrides digital_service; standard goods stay taxable.
    expect($this->taxability->isTaxable(usPlace('US-CA'), TaxCategory::Standard))->toBeTrue();
});

it('is the bound default ProductTaxability', function () {
    $bound = $this->app->make(ProductTaxability::class);

    expect($bound->isTaxable(usPlace('US-CA'), TaxCategory::DigitalService))->toBeFalse()
        ->and($bound->isTaxable(usPlace('US-NY'), TaxCategory::DigitalService))->toBeTrue();
});
