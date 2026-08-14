<?php

declare(strict_types=1);

namespace Cbox\Tax\Testing;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\Enums\ExemptionType;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\ValueObjects\BreakdownLine;
use Cbox\Tax\ValueObjects\RateBand;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxExemption;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/**
 * Test helper: build a calculator with the shipped regimes and a chosen rate map,
 * build buyer exemptions from ISO code strings, and assert exemption outcomes.
 * Dogfooded by this package's own suite.
 */
trait InteractsWithTax
{
    /**
     * @param  array<string, string>|null  $rates  Country code → percentage; null uses the built-in defaults.
     * @param  array<string, RateBand>  $bands  "<jurisdiction>:<category>" → reduced/zero band.
     */
    protected function taxCalculator(?array $rates = null, array $bands = []): TaxCalculator
    {
        return new DefaultTaxCalculator(
            DefaultRegimeRegistry::withDefaults(null, app(JurisdictionRepository::class)),
            new StaticTaxRateSource($rates, $bands),
        );
    }

    /**
     * Build a {@see TaxExemption} from ISO code strings — country codes ("DK") and
     * ISO 3166-2 subdivision codes ("US-CA") — so a consumer expresses coverage
     * without constructing the geo value objects by hand.
     *
     * @param  list<string>  $countries  Country-level coverage (EU/national VAT).
     * @param  list<string>  $subdivisions  Sub-federal coverage (US states, CA provinces).
     */
    protected function taxExemption(
        ExemptionType $type = ExemptionType::Resale,
        string $reference = 'TEST-EXEMPT',
        array $countries = [],
        array $subdivisions = [],
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
    ): TaxExemption {
        return new TaxExemption(
            type: $type,
            reference: $reference,
            countries: array_map(static fn (string $code): CountryCode => new CountryCode($code), $countries),
            subdivisions: array_map(static fn (string $code): SubdivisionCode => new SubdivisionCode($code), $subdivisions),
            validFrom: $validFrom,
            validUntil: $validUntil,
        );
    }

    /**
     * Assert an assessment's breakdown is present and reconciles: every
     * authority's share sums back to exactly the tax that was charged.
     *
     * This is the property a per-jurisdiction filing depends on, so it is worth
     * asserting directly rather than eyeballing the individual shares — a
     * breakdown that is one minor unit out is a filing that does not balance.
     *
     * @param  list<string>|null  $levels  Expected authority levels, in order.
     */
    protected function assertBreakdownReconciles(TaxAssessment $assessment, ?array $levels = null): void
    {
        Assert::assertNotNull($assessment->breakdown, 'Expected a breakdown on the assessment.');

        $total = $assessment->breakdown->total();

        Assert::assertNotNull($total, 'Expected a non-empty breakdown.');
        Assert::assertTrue(
            $total->isEqualTo($assessment->tax),
            sprintf('Breakdown sums to %s but the tax charged was %s.', $total, $assessment->tax),
        );

        if ($levels !== null) {
            Assert::assertSame(
                $levels,
                array_map(
                    static fn (BreakdownLine $line): string => $line->level->value,
                    $assessment->breakdown->lines,
                ),
                'Breakdown levels mismatch.',
            );
        }
    }

    /**
     * Assert an assessment is a native, certificate-driven exemption: `Exempt`
     * treatment, zero tax, gross kept equal to net, and the driving exemption
     * recorded on the assessment (optionally matching an expected reference).
     */
    protected function assertExempt(TaxAssessment $assessment, ?string $reference = null): void
    {
        Assert::assertSame(TaxTreatment::Exempt, $assessment->treatment, 'Expected an Exempt treatment.');
        Assert::assertTrue($assessment->tax->isZero(), 'Expected zero tax on an exempt supply.');
        Assert::assertTrue(
            $assessment->gross->isEqualTo($assessment->net),
            'Expected gross to equal net on an exempt supply.',
        );
        Assert::assertNotNull($assessment->exemption, 'Expected the driving exemption on the assessment.');

        if ($reference !== null) {
            Assert::assertSame($reference, $assessment->exemption->reference, 'Exemption reference mismatch.');
        }
    }
}
