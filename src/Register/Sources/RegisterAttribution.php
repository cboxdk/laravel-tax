<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\AttributionRules;
use Cbox\Tax\Enums\AttributionStatus;
use Cbox\Tax\Register\Reader\RateConditions;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\Attribution;
use Cbox\Tax\ValueObjects\DecisionFacts;
use DateTimeImmutable;

/**
 * The register's `attribution` rules, read with the same semantics as a rate's
 * conditions: all true, the rule applies; any false, it does not; otherwise unsettled.
 *
 * A rule without conditions says who accounts without saying for which supplies — the
 * form every member state's rule had before its scope was published, and the form
 * Belgium's keeps. It is `Unscoped`, never applied.
 */
readonly class RegisterAttribution implements AttributionRules
{
    public function __construct(private RegisterDataset $dataset) {}

    public function verdict(CountryCode $state, DecisionFacts $facts, DateTimeImmutable $on): Attribution
    {
        $rule = $this->dataset->rulesOn('eu:'.$state->value, 'attribution', $on)[0] ?? null;

        if ($rule === null) {
            return new Attribution;
        }

        $payload = Shape::map($rule['payload'] ?? null);
        $citation = Shape::text($rule['citation'] ?? null) ?? Shape::text($payload['citation'] ?? null);
        $conditions = $rule['conditions'] ?? $payload['conditions'] ?? null;

        if (Shape::records($conditions) === []) {
            return new Attribution(AttributionStatus::Unscoped, $citation);
        }

        $verdict = RateConditions::verdict(['conditions' => $conditions], $facts);

        return match ($verdict['status']) {
            RateConditions::APPLIES => new Attribution(
                Shape::text($payload['accountedForBy'] ?? null) === 'recipient' ? AttributionStatus::RecipientAccounts : AttributionStatus::SupplierAccounts,
                $citation,
            ),
            RateConditions::DOES_NOT_APPLY => new Attribution(AttributionStatus::SupplierAccounts, $citation),
            default => new Attribution(AttributionStatus::Unsettled, $citation, $verdict['unsettled']),
        };
    }
}
