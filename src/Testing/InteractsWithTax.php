<?php

declare(strict_types=1);

namespace Cbox\Tax\Testing;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\DefaultTaxCalculator;
use Cbox\Tax\Enums\ExemptionType;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Register\Reader\CategoryMap;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
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
     * A calculator over a register built for this test and nothing else.
     *
     * THE RATES BELOW ARE A FIXTURE, NOT DATA. They are a frozen set of headline
     * figures, maintained by nobody, kept only so an assertion about ARITHMETIC has
     * a number to work with — that 100.00 at Denmark's rate comes to 125.00, that an
     * inclusive price divides back correctly, that a breakdown reconciles. Pricing a
     * real invoice from them would be wrong the first time a state moved, which is
     * why the engine reads a synced register instead and refuses without one.
     *
     * @param  array<string, string>|null  $rates  Country or `US-XX` code → percentage; null uses the fixture below.
     * @param  array<string, RateBand>  $bands  "<jurisdiction>:<tax class>" → reduced/zero band.
     */
    protected function taxCalculator(?array $rates = null, array $bands = []): TaxCalculator
    {
        $dataset = $this->registerWith($rates ?? self::fixtureRates(), $bands);

        return new DefaultTaxCalculator(
            DefaultRegimeRegistry::withDefaults(null, app(JurisdictionRepository::class)),
            new RegisterRateSource($dataset),
        );
    }

    /**
     * Write a one-off store and return a reader over it.
     *
     * @param  array<string, string>  $rates
     * @param  array<string, RateBand>  $bands
     */
    protected function registerWith(array $rates, array $bands = []): RegisterDataset
    {
        $root = sys_get_temp_dir().'/cbox-tax-trait-'.getmypid().'-'.bin2hex(random_bytes(6));
        $register = FakeRegister::at($root);

        foreach ($rates as $code => $percentage) {
            $register->rate(self::registerCode($code), $percentage);
        }

        foreach ($bands as $key => $band) {
            [$place, $class] = array_pad(explode(':', $key, 2), 2, null);
            $case = $class === null ? null : TaxClass::tryFrom($class);

            if ($place === null || $case === null) {
                continue;
            }

            $register->rate(
                self::registerCode($place),
                (string) $band->percentage,
                $band->kind->value === 'zero' ? 'zero' : 'reduced',
                CategoryMap::keyFor($case),
            );
        }

        $register->install();
        $layout = new StoreLayout($root);

        return new RegisterDataset($layout, new StorePointer($layout));
    }

    /** `DK` is a country in the union's regime here; `US-KS` is a state. */
    private static function registerCode(string $code): string
    {
        return str_starts_with($code, 'US-') ? 'us:'.substr($code, 3) : 'eu:'.$code;
    }

    /**
     * Frozen headline rates, for arithmetic. See {@see TaxCalculator()}.
     *
     * @return array<string, string>
     */
    protected static function fixtureRates(): array
    {
        return [
            'DK' => '25', 'FR' => '20', 'DE' => '19', 'GB' => '20', 'PT' => '23', 'HU' => '27',
            'PL' => '23', 'ES' => '21', 'IE' => '23', 'GR' => '24', 'SE' => '25', 'NL' => '21',
            'IT' => '22', 'AT' => '20', 'BE' => '21', 'FI' => '25.5', 'LU' => '17', 'CZ' => '21',
            'RO' => '19', 'NO' => '25', 'CH' => '8.1', 'JP' => '10', 'SG' => '9', 'IN' => '18',
            'MY' => '8', 'AU' => '10', 'NZ' => '15', 'AE' => '5', 'SA' => '15', 'MX' => '16',
            'KR' => '10', 'TR' => '20', 'TH' => '7', 'ID' => '11', 'PH' => '12', 'VN' => '10',
            'CA' => '5', 'US' => '0',
        ];
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
