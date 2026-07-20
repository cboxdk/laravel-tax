<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\ExemptionType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\ValueObjects\OssStatus;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxExemption;
use Cbox\Tax\ValueObjects\TaxQuery;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->tax = $this->app->make(TaxCalculator::class);
});

function dkB2c(?TaxExemption $exemption = null): TaxQuery
{
    return new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('DK')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('DK')),
        exemption: $exemption,
    );
}

it('exempts a would-be standard supply when a valid exemption covers the jurisdiction', function () {
    $a = $this->tax->assess(dkB2c($this->taxExemption(
        type: ExemptionType::Resale,
        reference: 'RESALE-DK-001',
        countries: ['DK'],
    )));

    $this->assertExempt($a, 'RESALE-DK-001');

    expect((string) $a->tax->getAmount())->toBe('0.00')
        ->and((string) $a->net->getAmount())->toBe('100.00')
        ->and((string) $a->gross->getAmount())->toBe('100.00')
        ->and($a->rate)->toBeNull()
        ->and($a->exemption?->type)->toBe(ExemptionType::Resale)
        ->and($a->reason)->toContain('RESALE-DK-001');
});

it('charges the normal standard tax when no exemption is supplied (BC)', function () {
    $a = $this->tax->assess(dkB2c());

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('25.00')
        ->and((string) $a->gross->getAmount())->toBe('125.00')
        ->and($a->exemption)->toBeNull();
});

it('does not exempt when the exemption covers a different jurisdiction', function () {
    $a = $this->tax->assess(dkB2c($this->taxExemption(
        reference: 'RESALE-FR-001',
        countries: ['FR'], // buyer is in DK
    )));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('25.00')
        ->and($a->exemption)->toBeNull();
});

it('does not exempt when the exemption is expired', function () {
    $a = $this->tax->assess(dkB2c($this->taxExemption(
        reference: 'RESALE-DK-EXPIRED',
        countries: ['DK'],
        validUntil: new DateTimeImmutable('2000-01-01'),
    )));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('25.00')
        ->and($a->exemption)->toBeNull();
});

it('does not exempt when the exemption is not yet valid', function () {
    $a = $this->tax->assess(dkB2c($this->taxExemption(
        reference: 'RESALE-DK-FUTURE',
        countries: ['DK'],
        validFrom: new DateTimeImmutable('2999-01-01'),
    )));

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('25.00');
});

it('honours an open-ended exemption whose window contains now', function () {
    $a = $this->tax->assess(dkB2c($this->taxExemption(
        reference: 'RESALE-DK-OPEN',
        countries: ['DK'],
        validFrom: new DateTimeImmutable('2000-01-01'),
        validUntil: new DateTimeImmutable('2999-01-01'),
    )));

    $this->assertExempt($a, 'RESALE-DK-OPEN');
});

it('exempts a US state supply when the certificate covers that subdivision', function () {
    $query = new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-CA')),
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-CA')),
        ]),
        exemption: $this->taxExemption(
            type: ExemptionType::Resale,
            reference: 'CA-RESALE-42',
            subdivisions: ['US-CA'],
        ),
    );

    $this->assertExempt($this->tax->assess($query), 'CA-RESALE-42');
});

it('does not exempt a US state supply from a certificate for a different state', function () {
    $query = new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-CA')),
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-CA')),
        ]),
        exemption: $this->taxExemption(
            reference: 'NY-RESALE-9',
            subdivisions: ['US-NY'], // buyer is in CA
        ),
    );

    $a = $this->tax->assess($query);

    expect($a->treatment)->toBe(TaxTreatment::Standard)
        ->and((string) $a->tax->getAmount())->toBe('7.25')
        ->and($a->exemption)->toBeNull();
});

it('does not let a country-level certificate exempt a sub-federal state supply', function () {
    $query = new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-CA')),
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-CA')),
        ]),
        exemption: $this->taxExemption(
            reference: 'US-WIDE',
            countries: ['US'], // country-level does not cover a per-state certificate regime
        ),
    );

    expect($this->tax->assess($query)->treatment)->toBe(TaxTreatment::Standard);
});

it('leaves a reverse-charge supply untouched even with a covering exemption', function () {
    $a = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('FR')),
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode('DE')),
        customerTaxIdValidated: true,
        exemption: $this->taxExemption(reference: 'RC-FR', countries: ['FR']),
    ));

    expect($a->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and((string) $a->tax->getAmount())->toBe('0.00')
        ->and($a->exemption)->toBeNull();
});

it('leaves a not-registered US supply untouched even with a covering exemption', function () {
    $query = new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-CA')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-NY')), // nexus in NY, not CA
        ]),
        exemption: $this->taxExemption(reference: 'CA-RESALE', subdivisions: ['US-CA']),
    );

    $a = $this->tax->assess($query);

    expect($a->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($a->exemption)->toBeNull();
});

it('matches coverage against the origin-sourced place for EU micro-business relief', function () {
    // Below-threshold DE micro-business selling B2C into FR sources at origin (DE),
    // so an exemption must cover DE — the taxed jurisdiction — not FR.
    $seller = new SellerRegistrations(
        new CountryCode('DE'),
        [],
        new OssStatus(registered: false, thresholdExceeded: false),
    );

    $exemptDe = $this->tax->assess(new TaxQuery(
        amount: Money::of('100.00', 'EUR'),
        pricing: Pricing::Exclusive,
        place: $this->geo->find(new CountryCode('FR')),
        customer: CustomerType::Consumer,
        seller: $seller,
        exemption: $this->taxExemption(reference: 'DE-EXEMPT', countries: ['DE']),
    ));

    $this->assertExempt($exemptDe, 'DE-EXEMPT');
});
