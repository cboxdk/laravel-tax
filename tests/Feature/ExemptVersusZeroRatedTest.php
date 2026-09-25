<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Testing\FakeRegister;
use Cbox\Tax\ValueObjects\InvoiceMention;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;

/*
 * Zero-rated and exempt are both 0% to a price and opposite facts to a return.
 * Zero-rating keeps the seller's right to deduct the input tax behind the sale;
 * exemption removes it, and the two go in different boxes. The register files them
 * as different kinds — Ireland zero-rates books and exempts financial services — and
 * the engine collapsed both into one answer. In Canada, India, Malaysia and the US
 * a 0% rate did not even say zero-rated: it came back as a standard supply.
 */

beforeEach(function (): void {
    config()->set('tax.register.store', config('tax.register.store').'/exempt-versus-zero');

    FakeRegister::at(config('tax.register.store'))
        ->rate('eu:IE', '23', from: '1990-01-01')
        ->rate('eu:IE', '0', 'zero', 'goods.publications.book', from: '1990-01-01')
        ->rate('eu:IE', '0', 'exempt', 'services.financial', from: '1990-01-01')
        ->rate('ca:CA', '5', from: '1990-01-01')
        ->rate('ca:ON', '13', 'combined', from: '1990-01-01')
        ->rate('ca:CA', '0', 'exempt', 'services.medical', from: '1990-01-01')
        ->rate('ca:CA', '0', 'zero', 'goods.food.basic', from: '1990-01-01')
        ->rate('ca:ON', '0', 'zero', 'goods.food.basic', from: '1990-01-01')
        ->install();
});

function exemptOrZero(string $country, TaxClass $class, ?string $subdivision = null): TaxAssessment
{
    $geo = app(JurisdictionRepository::class);
    $place = $subdivision === null
        ? $geo->find(new CountryCode($country))
        : $geo->find(new CountryCode($country), new SubdivisionCode($subdivision));
    $currency = $country === 'CA' ? 'CAD' : 'EUR';

    return app(TaxCalculator::class)->assess(new TaxQuery(
        amount: Money::of('100.00', $currency),
        pricing: Pricing::Exclusive,
        place: $place,
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(
            new CountryCode($country),
            $subdivision === null ? [] : [new SellerRegistration(new CountryCode($country), new SubdivisionCode($subdivision))],
        ),
        category: $class,
        suppliedAt: new DateTimeImmutable('2026-09-25'),
    ));
}

it('tells an exempt supply from a zero-rated one in the EU', function (): void {
    $book = exemptOrZero('IE', TaxClass::Book);
    $finance = exemptOrZero('IE', TaxClass::FinancialAdmin);

    expect($book->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and($book->rate?->kind)->toBe(RateKind::Zero)
        ->and($book->mentions)->toBe([])
        ->and($finance->treatment)->toBe(TaxTreatment::Exempt)
        ->and($finance->isExempt())->toBeTrue()
        ->and($finance->rate?->kind)->toBe(RateKind::Exempt)
        ->and((string) $finance->tax->getAmount())->toBe('0.00')
        // Art. 226(11): an exempt supply's invoice must say so. The register names
        // no article per row, and the Directive accepts "any other reference
        // indicating that the supply is exempt" — so the words, and no invented
        // citation.
        ->and(array_map(fn (InvoiceMention $m): string => $m->code, $finance->mentions))->toBe(['exempt'])
        ->and($finance->mentions[0]->reference)->toBeNull();
});

it('files exempt and zero-rated sales on separate lines of the return', function (): void {
    $order = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        app(JurisdictionRepository::class)->find(new CountryCode('IE')),
        CustomerType::Consumer,
        new SellerRegistrations(new CountryCode('IE')),
        Pricing::Exclusive,
        [
            new SupplyLine('book', Money::of('40.00', 'EUR'), TaxClass::Book),
            new SupplyLine('advice', Money::of('60.00', 'EUR'), TaxClass::FinancialAdmin),
        ],
        suppliedAt: new DateTimeImmutable('2026-09-25'),
    ));

    $line = app(ReturnAggregator::class)->aggregate($order->assessments())->lineFor(new CountryCode('IE'), 'EUR');

    expect((string) $line?->forTreatment(TaxTreatment::ZeroRated)?->net->getAmount())->toBe('40.00')
        ->and((string) $line?->forTreatment(TaxTreatment::Exempt)?->net->getAmount())->toBe('60.00');
});

it('does not call a 0% Canadian supply standard-rated', function (): void {
    $care = exemptOrZero('CA', TaxClass::MedicalCare, 'CA-ON');
    $food = exemptOrZero('CA', TaxClass::Groceries, 'CA-ON');
    $general = exemptOrZero('CA', TaxClass::GeneralGoods, 'CA-ON');

    expect($care->treatment)->toBe(TaxTreatment::Exempt)
        ->and($food->treatment)->toBe(TaxTreatment::ZeroRated)
        ->and((string) $general->rate?->percentage)->toBe('13')
        ->and($general->treatment)->toBe(TaxTreatment::Standard);
});

it('asks whether the seller collects there before asking which kind of nothing it is', function (): void {
    // A seller neither established nor registered in a country collects nothing there
    // and files no return there. The gate that says so only looked at standard-rated
    // supplies, so a zero-rated or exempt one from such a seller went on its return as
    // a line in a country it does not file in. Canada's 0% supplies escaped nothing
    // before only because they were mislabelled standard.
    $abroad = function (string $country, TaxClass $class, ?string $subdivision = null) {
        $geo = app(JurisdictionRepository::class);

        return app(TaxCalculator::class)->assess(new TaxQuery(
            amount: Money::of('100.00', $country === 'CA' ? 'CAD' : 'EUR'),
            pricing: Pricing::Exclusive,
            place: $subdivision === null ? $geo->find(new CountryCode($country)) : $geo->find(new CountryCode($country), new SubdivisionCode($subdivision)),
            customer: CustomerType::Consumer,
            seller: new SellerRegistrations(new CountryCode('US')),
            category: $class,
            suppliedAt: new DateTimeImmutable('2026-09-25'),
        ));
    };

    expect($abroad('IE', TaxClass::Book)->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($abroad('IE', TaxClass::FinancialAdmin)->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($abroad('CA', TaxClass::MedicalCare, 'CA-ON')->treatment)->toBe(TaxTreatment::NotRegistered)
        ->and($abroad('CA', TaxClass::Groceries, 'CA-ON')->treatment)->toBe(TaxTreatment::NotRegistered);
});
