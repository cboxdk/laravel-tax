<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Enums\TaxCategory;
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

it('leaves undetermined states absent (falls through to the taxable default)', function (string $state) {
    // AL, MS (source conflict) and TX, IA, OH, MD (conditional/partial) are NOT in
    // the curated map: the boolean contract cannot represent "undetermined", so they
    // fall through to the safe over-collection default (taxable). Operators MUST
    // configure these — see docs/coverage/us-saas-taxability.md.
    $overrides = StaticProductTaxability::unitedStatesSaas();
    expect($overrides)->not->toHaveKey($state.':'.TaxCategory::DigitalService->value);
})->with(['US-AL', 'US-MS', 'US-TX', 'US-IA', 'US-OH', 'US-MD']);

it('does not touch tangible-goods (standard) taxability', function () {
    // The SaaS map only overrides digital_service; standard goods stay taxable.
    expect($this->taxability->isTaxable(usPlace('US-CA'), TaxCategory::Standard))->toBeTrue();
});

it('is the bound default ProductTaxability', function () {
    $bound = $this->app->make(ProductTaxability::class);

    expect($bound->isTaxable(usPlace('US-CA'), TaxCategory::DigitalService))->toBeFalse()
        ->and($bound->isTaxable(usPlace('US-NY'), TaxCategory::DigitalService))->toBeTrue();
});
