<?php

declare(strict_types=1);

namespace Cbox\Tax;

use Brick\Money\Money;
use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\Exceptions\UnsupportedJurisdiction;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;
use DateTimeImmutable;

/**
 * The engine entry point. Reads the place of supply's tax profile (from geo),
 * selects the regime keyed by its `regimeModule`, and delegates. Deny-by-default:
 * an unmodelled jurisdiction or an unregistered regime is refused.
 *
 * A buyer exemption ({@see TaxQuery::$exemption}) is applied last, as a native
 * override of the regime's verdict: it exempts ONLY a would-be standard-taxed
 * supply, and only when the exemption is valid and covers the taxed jurisdiction.
 * Reverse-charge, not-registered, zero-rated and already-exempt outcomes are left
 * untouched — the regime's precedence wins, exactly as an app-layer decorator over
 * this calculator would behave, but now native.
 */
readonly class DefaultTaxCalculator implements TaxCalculator
{
    public function __construct(
        private RegimeRegistry $registry,
        private TaxRateSource $rates,
    ) {}

    public function assess(TaxQuery $query): TaxAssessment
    {
        $module = $query->place->taxProfile->regimeModule;

        if ($module === null) {
            throw UnsupportedJurisdiction::for($query->place->country);
        }

        $regime = $this->registry->for($module);

        if ($regime === null) {
            throw UnsupportedJurisdiction::for($query->place->country);
        }

        $assessment = $regime->assess($query, $this->rates);

        return $this->applyExemption($query, $assessment);
    }

    /**
     * Rewrite a would-be standard-taxed line to a native `Exempt` assessment when
     * the query carries a valid exemption that covers the place the supply was
     * taxed in. Deny-by-default: no exemption, a non-standard treatment, an
     * expired/not-yet-valid exemption, or one that does not cover the place of
     * supply all leave the regime's assessment unchanged.
     */
    private function applyExemption(TaxQuery $query, TaxAssessment $assessment): TaxAssessment
    {
        $exemption = $query->exemption;

        if ($exemption === null || $assessment->treatment !== TaxTreatment::Standard) {
            return $assessment;
        }

        $place = $assessment->placeOfSupply;

        if (! $exemption->appliesTo($place, new DateTimeImmutable)) {
            return $assessment;
        }

        $where = $place->subdivision !== null ? $place->subdivision->value : $place->country->value;

        return new TaxAssessment(
            treatment: TaxTreatment::Exempt,
            net: $assessment->net,
            tax: Money::zero($assessment->net->getCurrency(), $assessment->net->getContext()),
            gross: $assessment->net,
            placeOfSupply: $place,
            rate: null,
            reason: sprintf('Exempt: %s covers %s; standard tax overridden.', $exemption->describe(), $where),
            exemption: $exemption,
        );
    }
}
