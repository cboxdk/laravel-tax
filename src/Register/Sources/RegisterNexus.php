<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Enums\NexusCombinator;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\NexusThreshold;

/**
 * Economic-nexus thresholds, from the register's `threshold` rules.
 *
 * Only a threshold that BINDS A REMOTE SELLER is one of these. The same rule kind
 * also carries registration thresholds for established businesses — Malaysia's
 * RM500,000, Denmark's repealed § 48 — and reading one of those as a US nexus test
 * would tell a seller they have no obligation until they cross a number written for
 * somebody else entirely.
 *
 * ONLY DOLLARS ARE COMPARED. A threshold published in another currency is refused
 * rather than converted: the rate to convert at is a decision with a date on it, and
 * this is not the place to make it.
 */
final readonly class RegisterNexus implements NexusThresholds
{
    public function __construct(private RegisterDataset $dataset) {}

    public function for(SubdivisionCode $state): ?NexusThreshold
    {
        foreach ($this->dataset->rulesFor(UsCode::of($state), 'threshold') as $rule) {
            $payload = Shape::map($rule['payload'] ?? null);

            if (Shape::text($payload['binds'] ?? null) !== 'remote_seller') {
                continue;
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

            return new NexusThreshold(
                (int) round((float) $amount),
                $transactions,
                $this->combinator($payload, $transactions),
            );
        }

        return null;
    }

    /**
     * How the two limbs combine.
     *
     * The register states it where a state does; where it does not, a threshold with
     * no transaction count can only be sales-only, and one with a count is `or` —
     * which is what every state that kept a transaction limb actually legislated,
     * and the direction that registers a seller SOONER rather than later.
     *
     * @param  array<string, mixed>  $payload
     */
    private function combinator(array $payload, ?int $transactions): NexusCombinator
    {
        $stated = Shape::text($payload['combinator'] ?? null);

        if ($stated !== null) {
            $mapped = match ($stated) {
                'and' => NexusCombinator::SalesAndTransactions,
                'or' => NexusCombinator::SalesOrTransactions,
                default => null,
            };

            if ($mapped !== null) {
                return $mapped;
            }
        }

        return $transactions === null
            ? NexusCombinator::SalesOnly
            : NexusCombinator::SalesOrTransactions;
    }
}
