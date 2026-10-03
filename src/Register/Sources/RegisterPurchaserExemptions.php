<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\PurchaserExemptions;
use Cbox\Tax\Enums\PurchaserExemptionEffect;
use Cbox\Tax\Enums\PurchaserExemptionRoute;
use Cbox\Tax\Enums\PurchaserExemptionStatus;
use Cbox\Tax\Enums\PurchaserType;
use Cbox\Tax\Register\Reader\Predicate;
use Cbox\Tax\Register\Reader\RateConditions;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\DecisionFacts;
use Cbox\Tax\ValueObjects\PurchaserExemption;
use DateTimeImmutable;

/**
 * The register's `purchaser_exemption` rules: one per place and purchaser, held at the
 * state or member state, or at the regime (`eu`) for what binds every member — Art. 151.
 * A member's own rule wins over the regime's.
 *
 * Conditions read as a rate's do: all true, the rule applies; any false, it does not;
 * otherwise unsettled. A rule whose effect or certificate cannot be read answers
 * unsettled rather than exempting: the cost of a wrong exemption is the seller's.
 */
readonly class RegisterPurchaserExemptions implements PurchaserExemptions
{
    public function __construct(private RegisterDataset $dataset) {}

    public function for(Jurisdiction $place, PurchaserType $purchaser, DecisionFacts $facts, DateTimeImmutable $on): PurchaserExemption
    {
        $rule = $this->rule($place, $purchaser, $on);

        if ($rule === null) {
            return new PurchaserExemption;
        }

        $payload = Shape::map($rule['payload'] ?? null);
        $effect = PurchaserExemptionEffect::tryFrom(Shape::text($payload['effect'] ?? null) ?? '');
        $rate = $this->rate($payload['rate'] ?? null);
        $route = PurchaserExemptionRoute::tryFrom(Shape::text($payload['route'] ?? null) ?? PurchaserExemptionRoute::AtSource->value);
        $certificate = Shape::map($payload['certificate'] ?? null);
        $citation = Shape::text($payload['citation'] ?? null);
        $says = Shape::text($payload['says'] ?? null);
        $unreadable = $effect === null || $route === null || ($effect === PurchaserExemptionEffect::Reduced && $rate === null);

        $verdict = $unreadable
            ? ['status' => RateConditions::UNSETTLED, 'unsettled' => []]
            : RateConditions::verdict(['conditions' => $payload['conditions'] ?? null], $facts);

        return new PurchaserExemption(
            status: match ($verdict['status']) {
                RateConditions::APPLIES => PurchaserExemptionStatus::Applies,
                RateConditions::DOES_NOT_APPLY => PurchaserExemptionStatus::DoesNotApply,
                default => PurchaserExemptionStatus::Unsettled,
            },
            effect: $effect ?? PurchaserExemptionEffect::Taxable,
            rate: $rate,
            purchaserAccounts: Shape::text($payload['accountedForBy'] ?? null) === 'customer',
            route: $route ?? PurchaserExemptionRoute::AtSource,
            certificateRequired: $this->certificateRequired($certificate, $facts),
            certificateForm: Shape::text($certificate['form'] ?? null),
            citation: $citation,
            says: $says,
            unsettled: $verdict['unsettled'],
        );
    }

    /**
     * The place's own rule for the purchaser, else its regime's.
     *
     * @return array<string, mixed>|null
     */
    private function rule(Jurisdiction $place, PurchaserType $purchaser, DateTimeImmutable $on): ?array
    {
        $codes = $this->codes($place, $on);

        foreach ($codes as $code) {
            foreach ($this->dataset->rulesOn($code, 'purchaser_exemption', $on) as $rule) {
                if (Shape::text(Shape::map($rule['payload'] ?? null)['purchaser'] ?? null) === $purchaser->value) {
                    return $rule;
                }
            }
        }

        return null;
    }

    /**
     * Most specific first: the US state, else the country's own code; then the regime
     * that code belongs to (`eu` for `eu:DK`).
     *
     * @return list<string>
     */
    private function codes(Jurisdiction $place, DateTimeImmutable $on): array
    {
        $own = $place->subdivision !== null && $place->country->value === 'US'
            ? UsCode::of($place->subdivision)
            : ($this->dataset->codesForCountry($place->country->value, $on)[0] ?? null);

        if ($own === null) {
            return [];
        }

        $regime = strstr($own, ':', true);

        return $regime === false ? [$own] : [$own, $regime];
    }

    /**
     * Whether the seller must hold a certificate. `requiredWhen` narrows a required
     * certificate to the cases its predicate names — Annex II only for a recipient
     * established in another member state — and a predicate the facts leave unknown
     * answers null: the seller cannot tell, so it is treated as required.
     *
     * @param  array<string, mixed>  $certificate
     */
    private function certificateRequired(array $certificate, DecisionFacts $facts): ?bool
    {
        if (($certificate['required'] ?? null) !== true) {
            return ($certificate['required'] ?? null) === false ? false : null;
        }

        $when = $certificate['requiredWhen'] ?? null;

        return is_array($when) ? Predicate::evaluate(Shape::map($when), $facts) : true;
    }

    private function rate(mixed $rate): ?BigDecimal
    {
        $text = Shape::text($rate);

        if ($text === null) {
            return null;
        }

        try {
            $decimal = BigDecimal::of($text);
        } catch (MathException) {
            return null;
        }

        return $decimal->isNegative() || $decimal->isGreaterThan(100) ? null : $decimal;
    }
}
