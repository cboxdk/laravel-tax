<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Brick\Math\BigDecimal;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Enums\NexusCombinator;
use Cbox\Tax\Enums\ThresholdOperator;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Register\Reader\RegisterCompatibility;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\NexusThreshold;
use Cbox\Tax\ValueObjects\ThresholdMeasurement;
use Cbox\Tax\ValueObjects\ThresholdObligation;
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
readonly class RegisterNexus implements NexusThresholds
{
    /** @var list<string> */
    private const array ACTIONS = ['register', 'collect', 'remit', 'registration_effective', 'cancel_registration'];

    /** @var list<string> */
    private const array TRIGGERS = ['threshold_met', 'first_crossing_in_current_year', 'quarter_end_test_met', 'registration_filed', 'measurement_window_end'];

    /**
     * Every date kind schema 2.8 names, with the figures each one carries. A kind
     * outside this list, or a figure it does not carry, refuses: the date it gives
     * is a different date, and handing a host the kind without it would be
     * presenting a qualified obligation as a plain one.
     *
     * @var array<string, list<string>>
     */
    private const array DATE_KINDS = [
        'at_trigger' => [],
        'first_day_of_next_calendar_year' => [],
        'calendar_days_after' => ['days'],
        'first_month_start_on_or_after_days' => ['days'],
        'first_month_start_after_days' => ['days'],
        'earlier_of_days_after_or_next_year' => ['days'],
        'first_day_of_nth_following_month' => ['months'],
        'calendar_days_after_month_end' => ['days'],
        'calendar_days_including_trigger' => ['days'],
        'working_days_after' => ['days'],
        'last_month_start_on_or_before_days' => ['days'],
        'next_calendar_date' => ['month', 'day'],
        'date_in_year_after_trigger' => ['month', 'day'],
        'return_due_after_following_filing_period' => ['days', 'annualReturnMonth'],
    ];

    /** The bounds of each figure, as the schema states them. */
    private const array FIGURE_RANGE = [
        'days' => [1, 366],
        'months' => [1, 366],
        'month' => [1, 12],
        'day' => [1, 31],
        'annualReturnMonth' => [1, 12],
    ];

    public function __construct(private RegisterData $dataset) {}

    public function for(SubdivisionCode $state, ?DateTimeImmutable $at = null): ?NexusThreshold
    {
        $threshold = null;

        foreach ($this->dataset->rulesOn(UsCode::of($state), 'threshold', $at ?? new DateTimeImmutable) as $rule) {
            $payload = Shape::map($rule['payload'] ?? null);

            if (Shape::text($payload['binds'] ?? null) !== 'remote_seller') {
                continue;
            }

            RegisterCompatibility::applicable($rule);

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
                $this->operator($payload, 'amountOperator', $state),
                $transactions === null ? null : $this->operator($payload, 'transactionsOperator', $state),
                $this->measurements($payload),
                $this->obligations($payload, $state),
                Shape::text($payload['measuredOver'] ?? null),
                Shape::text($payload['counts'] ?? null),
                Shape::text($payload['basis'] ?? null),
                Shape::text(Shape::map($rule['provenance'] ?? null)['quote'] ?? null),
                Shape::text(Shape::map($rule['provenance'] ?? null)['source'] ?? null),
                Shape::text(Shape::map($rule['provenance'] ?? null)['note'] ?? null),
            );
        }

        return $threshold;
    }

    /**
     * What the state counts toward the figure, in its own words.
     *
     * @param  array<string, mixed>  $payload
     * @return list<ThresholdMeasurement>
     */
    private function measurements(array $payload): array
    {
        $rules = [];

        foreach (Shape::records($payload['measurementRules'] ?? null) as $rule) {
            $dimension = Shape::text($rule['dimension'] ?? null);
            $treatment = Shape::text($rule['treatment'] ?? null);
            $says = Shape::text($rule['says'] ?? null);

            if ($dimension === null || $treatment === null || $says === null) {
                continue;
            }

            $rules[] = new ThresholdMeasurement($dimension, $treatment, $says);
        }

        return $rules;
    }

    /**
     * What crossing obliges, and from when.
     *
     * READ WHOLE OR REFUSED. Every action, trigger and date kind is one the schema
     * names, with exactly the figures that kind carries; anything else — a kind a
     * newer schema adds, a figure missing or out of range — refuses rather than
     * being dropped or handed over without the part that dates it. Dropping one
     * left a state looking as though crossing obliged nothing.
     *
     * @param  array<string, mixed>  $payload
     * @return list<ThresholdObligation>
     */
    private function obligations(array $payload, SubdivisionCode $state): array
    {
        $obligations = [];

        foreach (Shape::records($payload['obligations'] ?? null) as $rule) {
            $action = Shape::text($rule['action'] ?? null);
            $trigger = Shape::text($rule['trigger'] ?? null);
            $says = Shape::text($rule['says'] ?? null);
            $date = Shape::map($rule['date'] ?? null);
            $kind = Shape::text($date['kind'] ?? null);
            $figures = self::DATE_KINDS[$kind ?? ''] ?? null;
            $unknown = array_diff(array_keys($rule), ['action', 'trigger', 'date', 'says', 'source']);

            if ($action === null || ! in_array($action, self::ACTIONS, true)
                || $trigger === null || ! in_array($trigger, self::TRIGGERS, true)
                || $says === null || $figures === null || $unknown !== []
                || array_diff(array_keys($date), ['kind', ...$figures]) !== []) {
                throw new UnresolvedTaxRule(sprintf(
                    'Unsupported obligation on the remote-seller threshold for %s (action %s, trigger %s, date kind %s). Upgrade the reader; the obligation cannot be read without it.',
                    $state->value,
                    $action ?? '(none)',
                    $trigger ?? '(none)',
                    $kind ?? '(none)',
                ));
            }

            $read = [];

            foreach ($figures as $field) {
                $value = $date[$field] ?? null;
                [$min, $max] = self::FIGURE_RANGE[$field];

                if (! is_int($value) || $value < $min || $value > $max) {
                    throw new UnresolvedTaxRule(sprintf('The %s obligation on the remote-seller threshold for %s carries no readable %s.', $kind, $state->value, $field));
                }

                $read[$field] = $value;
            }

            // A FIXED DATE IS CARRIED WHOLE. `next_calendar_date` names a month and a
            // day — North Macedonia's "by 15 January" — and reading only a count gave
            // a host the kind with no date in it.
            $obligations[] = new ThresholdObligation(
                $action,
                $trigger,
                (string) $kind,
                $read['days'] ?? $read['months'] ?? null,
                $says,
                $read['month'] ?? null,
                $read['day'] ?? null,
                $read['annualReturnMonth'] ?? null,
            );
        }

        return $obligations;
    }

    /**
     * How one limb is crossed. Absent is permitted — most thresholds do not say — but
     * a stated operator this reader does not know refuses, like an unknown combinator.
     *
     * @param  array<string, mixed>  $payload
     */
    private function operator(array $payload, string $field, SubdivisionCode $state): ?ThresholdOperator
    {
        if (! array_key_exists($field, $payload)) {
            return null;
        }

        $stated = Shape::text($payload[$field]);

        return ($stated === null ? null : ThresholdOperator::tryFrom($stated))
            ?? throw new UnresolvedTaxRule(sprintf('Unsupported %s "%s" on the remote-seller threshold for %s.', $field, $stated ?? '(empty)', $state->value));
    }

    /**
     * How the two limbs combine.    /**
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
