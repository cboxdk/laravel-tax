<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Charges\NoFlatCharges;
use Cbox\Tax\Charges\NoOrderFlatCharges;
use Cbox\Tax\Contracts\FlatChargeSource;
use Cbox\Tax\Contracts\OrderFlatChargeSource;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\JurisdictionLevel;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\ValueObjects\FlatCharge;
use Cbox\Tax\ValueObjects\OrderAssessment;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;

// TaxRate is a percentage and refuses to be anything else — deliberately, and the
// refusal is load-bearing elsewhere. That made a real class of charge
// inexpressible: Colorado's Retail Delivery Fee is $0.31 PER ORDER from 1 July
// 2026, Minnesota's is $0.50, and neither is a percentage of anything. A caller
// could only fake one as a rate derived from that order's total.

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
});

/** A source levying a fixed fee on any supply that was actually taxed. */
function deliveryFeeSource(string $amount = '0.31', bool $passedToBuyer = true): FlatChargeSource
{
    return new class($amount, $passedToBuyer) implements FlatChargeSource
    {
        public function __construct(private string $amount, private bool $passedToBuyer) {}

        public function chargesFor(TaxQuery $query, TaxAssessment $assessment): array
        {
            // Colorado's fee is due on a delivery containing taxable goods, which is
            // exactly why the source is handed the ASSESSMENT and not just the query.
            if ($assessment->treatment !== TaxTreatment::Standard) {
                return [];
            }

            return [new FlatCharge(
                code: 'co_retail_delivery_fee',
                name: 'Retail Delivery Fee',
                amount: Money::of($this->amount, 'USD'),
                level: JurisdictionLevel::State,
                passedToBuyer: $this->passedToBuyer,
            )];
        }
    };
}

function chargedCalculator(FlatChargeSource $charges): DefaultTaxCalculator
{
    return new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(new StaticProductTaxability, test()->geo),
        new StaticTaxRateSource(['US-CO' => '2.9']),
        $charges,
    );
}

function coloradoSupply(): TaxQuery
{
    return new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('US'), new SubdivisionCode('US-CO')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-CO')),
        ]),
    );
}

it('carries a fixed charge alongside the rate-based tax', function () {
    $assessment = chargedCalculator(deliveryFeeSource())->assess(coloradoSupply());

    expect($assessment->charges)->toHaveCount(1)
        ->and($assessment->charges[0]->code)->toBe('co_retail_delivery_fee')
        ->and((string) $assessment->charges[0]->amount->getAmount())->toBe('0.31');
});

it('keeps gross as net plus tax, and adds the charge in payable', function () {
    // The net + tax = gross invariant holds throughout the engine and several
    // things depend on it, so a fixed charge sits beside it rather than inside it.
    $assessment = chargedCalculator(deliveryFeeSource())->assess(coloradoSupply());

    expect((string) $assessment->net->getAmount())->toBe('100.00')
        ->and((string) $assessment->tax->getAmount())->toBe('2.90')
        ->and((string) $assessment->gross->getAmount())->toBe('102.90')
        ->and((string) $assessment->payable()->getAmount())->toBe('103.21');
});

it('excludes a charge the seller must absorb from what the buyer pays', function () {
    // Some levies are the seller's own cost by statute. Reporting one without
    // saying so would put it on a customer's invoice.
    $assessment = chargedCalculator(deliveryFeeSource(passedToBuyer: false))->assess(coloradoSupply());

    expect($assessment->charges)->toHaveCount(1)
        ->and($assessment->chargesTotal())->toBeNull()
        ->and((string) $assessment->payable()->getAmount())->toBe('102.90');
});

it('lets the source decide from the outcome, not just the query', function () {
    // An exempt supply attracts no delivery fee, and the source can only know that
    // because it sees the assessment.
    $exempt = new TaxQuery(
        amount: Money::of('100.00', 'USD'),
        pricing: Pricing::Exclusive,
        place: test()->geo->find(new CountryCode('US'), new SubdivisionCode('US-CO')),
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-CO')),
        ]),
        exemption: $this->taxExemption(subdivisions: ['US-CO']),
    );

    $assessment = chargedCalculator(deliveryFeeSource())->assess($exempt);

    $this->assertExempt($assessment);
    expect($assessment->charges)->toBe([]);
});

it('ships no document-level charges either, and binds that seam too', function () {
    expect($this->app->make(OrderFlatChargeSource::class))->toBeInstanceOf(NoOrderFlatCharges::class);

    $assessment = $this->app->make(OrderTaxCalculator::class)->assessOrder(coloradoOrder(lines: 2));

    expect($assessment->charges)->toBe([])
        ->and($assessment->payable()->isEqualTo($assessment->gross()))->toBeTrue();
});

it('ships no charges at all, and says so', function () {
    // These levies are per-jurisdiction and move on their own schedule, and no
    // authoritative compilation of them sits behind this package. The default
    // states that plainly rather than fabricating one.
    expect($this->app->make(FlatChargeSource::class))->toBeInstanceOf(NoFlatCharges::class);

    $assessment = $this->app->make(TaxCalculator::class)->assess(coloradoSupply());

    expect($assessment->charges)->toBe([])
        ->and($assessment->payable()->isEqualTo($assessment->gross))->toBeTrue();
});

// ---- Per delivery, not per line -------------------------------------------
//
// Colorado's fee is $0.31 per DELIVERY. Run through the per-supply seam it was
// applied once per line, so a two-line order paid $0.62 for one delivery. No care
// inside a per-supply source could fix that: it is handed one line at a time and
// cannot see that the lines share a delivery. That is why the document-level seam
// exists rather than a grouping rule on the per-supply one.

function coloradoOrder(int $lines = 2): TaxOrder
{
    return new TaxOrder(
        place: test()->geo->find(new CountryCode('US'), new SubdivisionCode('US-CO')),
        customer: CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-CO')),
        ]),
        pricing: Pricing::Exclusive,
        lines: array_map(
            static fn (int $i): SupplyLine => new SupplyLine(
                id: 'line-'.$i,
                amount: Money::of('50.00', 'USD'),
            ),
            range(1, $lines),
        ),
    );
}

/** A document-level source levying the delivery fee once, when anything was taxed. */
function deliveryFeeOnOrder(string $amount = '0.31'): OrderFlatChargeSource
{
    return new class($amount) implements OrderFlatChargeSource
    {
        public function __construct(private string $amount) {}

        public function chargesFor(TaxOrder $order, OrderAssessment $assessment): array
        {
            foreach ($assessment->assessments() as $line) {
                if ($line->treatment === TaxTreatment::Standard) {
                    return [new FlatCharge(
                        code: 'co_retail_delivery_fee',
                        name: 'Retail Delivery Fee',
                        amount: Money::of($this->amount, 'USD'),
                        level: JurisdictionLevel::State,
                    )];
                }
            }

            return [];
        }
    };
}

function orderCalculator(?FlatChargeSource $perSupply, ?OrderFlatChargeSource $perOrder): DefaultTaxCalculator
{
    return new DefaultTaxCalculator(
        DefaultRegimeRegistry::withDefaults(new StaticProductTaxability, test()->geo),
        new StaticTaxRateSource(['US-CO' => '2.9']),
        $perSupply,
        $perOrder,
    );
}

it('levies a per-delivery fee ONCE on a multi-line order', function () {
    $assessment = orderCalculator(null, deliveryFeeOnOrder())->assessOrder(coloradoOrder(lines: 4));

    expect($assessment->charges)->toHaveCount(1)
        ->and((string) $assessment->chargesTotal()?->getAmount())->toBe('0.31')
        // Four lines of $50 at 2.9%: net 200.00, tax 5.80, and one fee on top.
        ->and((string) $assessment->net()->getAmount())->toBe('200.00')
        ->and((string) $assessment->gross()->getAmount())->toBe('205.80')
        ->and((string) $assessment->payable()->getAmount())->toBe('206.11');
});

it('does not run the per-supply charge source over a document at all', function () {
    // The per-supply source is bound and would have charged every line. Within a
    // document it is not consulted — the document's own seam decides.
    $assessment = orderCalculator(deliveryFeeSource(), null)->assessOrder(coloradoOrder(lines: 2));

    $perLine = 0;

    foreach ($assessment->assessments() as $line) {
        $perLine += count($line->charges);
    }

    expect($perLine)->toBe(0)
        ->and($assessment->charges)->toBe([])
        ->and($assessment->payable()->isEqualTo($assessment->gross()))->toBeTrue();
});

it('still levies the per-supply charge on a standalone supply', function () {
    // A single supply IS the transaction, so the per-supply seam remains right
    // there — and this is the path that existed before documents did.
    $assessment = orderCalculator(deliveryFeeSource(), null)->assess(coloradoSupply());

    expect($assessment->charges)->toHaveCount(1)
        ->and((string) $assessment->payable()->getAmount())->toBe('103.21');
});

it('levies nothing on a document whose lines were all exempt', function () {
    // The source is handed the finished assessment for exactly this reason: no
    // taxable goods were delivered, so no delivery fee is due.
    $order = new TaxOrder(
        place: $this->geo->find(new CountryCode('US'), new SubdivisionCode('US-CO')),
        customer: CustomerType::Business,
        seller: new SellerRegistrations(new CountryCode('US'), [
            new SellerRegistration(new CountryCode('US'), new SubdivisionCode('US-CO')),
        ]),
        pricing: Pricing::Exclusive,
        lines: [new SupplyLine(id: 'a', amount: Money::of('50.00', 'USD'))],
        exemption: $this->taxExemption(subdivisions: ['US-CO']),
    );

    expect(orderCalculator(null, deliveryFeeOnOrder())->assessOrder($order)->charges)->toBe([]);
});
