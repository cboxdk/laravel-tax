<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Brick\Math\BigDecimal;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Enums\NexusCombinator;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\NexusThreshold;
use DateTimeImmutable;

/**
 * Economic-nexus thresholds, from the register's `threshold` rules.
 *
 * Only a threshold that BINDS A REMOTE SELLER is one of these. The same rule kind
 * also carries registration thresholds for established businesses — Malaysia's
 * RM500,000, Denmark's repealed § 48 — and reading one of those as a US nexus test
 * would tell a seller they have no obligation until they cross a number written for
 * somebody else entirely.
 *
 * `crossing` describes WHAT crossing triggers, not > versus >=. These figures
 * are advisory; neither that effect nor the comparison of seller totals is
 * evaluated here. The combinator alone describes how the two figures combine.
 *
 * Only USD figures are exposed. Other currencies are not converted into an
 * advisory dollar figure: a conversion would need its own dated policy.
 */
final readonly class RegisterNexus implements NexusThresholds
{
    public function __construct(private RegisterDataset $dataset) {}

    public function for(SubdivisionCode $state, ?DateTimeImmutable $at = null): ?NexusThreshold
    {
        $threshold = null;

        foreach ($this->dataset->rulesOn(UsCode::of($state), 'threshold', $at ?? new DateTimeImmutable) as $rule) {
            $payload = Shape::map($rule['payload'] ?? null);

            if (Shape::text($payload['binds'] ?? null) !== 'remote_seller') {
                continue;
            }

            if (array_key_exists('conditions', $payload) || array_key_exists('conditionsCombinator', $payload)) {
                throw new UnresolvedTaxRule('Unsupported compound remote-seller threshold for '.$state->value.'.');
            }

            if (Shape::text($payload['currency'] ?? null) !== 'USD') {
                continue;
            }

            $amount = Shape::text($payload['amount'] ?? null);

            if ($amount === null) {
                continue;
            }

            $transactions = $payload['transactions'] ?? null;
            $transactions = is_int($transactions) ? $transactions : (is_numeric($transactions) ? (int) $transactions : null);

            if ($threshold !== null) {
                throw new UnresolvedTaxRule('Overlapping remote-seller thresholds for '.$state->value.'.');
            }

            $threshold = new NexusThreshold(
                BigDecimal::of($amount)->toInt(),
                $transactions,
                $this->combinator($payload, $transactions),
            );
        }

        return $threshold;
    }

    /**
     * How the two limbs combine.
     *
     * A missing count permits sales-only. Two limbs require an explicit operator;
     * guessing OR can change a published AND rule into a different obligation.
     *
     * @param  array<string, mixed>  $payload
     */
    private function combinator(array $payload, ?int $transactions): NexusCombinator
    {
        $stated = Shape::text($payload['combinator'] ?? null);

        return match (true) {
            $transactions === null && ($stated === null || $stated === 'sales_only') => NexusCombinator::SalesOnly,
            $transactions !== null && in_array($stated, ['and', 'sales_and_transactions'], true) => NexusCombinator::SalesAndTransactions,
            $transactions !== null && in_array($stated, ['or', 'sales_or_transactions'], true) => NexusCombinator::SalesOrTransactions,
            default => throw new UnresolvedTaxRule('Missing or unsupported nexus combinator: '.($stated ?? '(absent)').'.'),
        };
    }
}
