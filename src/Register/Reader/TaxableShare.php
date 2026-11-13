<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

use Brick\Math\BigDecimal;
use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Contracts\TaxRegister;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * A state that taxes only a share of the price: a `taxable_base` rule on the
 * `supplied_item` itself, with a `proportion` and, from schema 2.8, `localTaxes`.
 *
 * Texas taxes data processing, SaaS and hosting on 80% of the price (34 TAC
 * §3.330(c)(1),(4)); Arizona taxes prime contracting on 65% of the gross. The
 * register publishes the state's own rate as it is, and this rule beside it. The
 * rate stays the statutory one and the share travels with it; see
 * {@see TaxRate::$taxableShare}.
 *
 * WHAT `localTaxes` SAYS, and why the rule is not applied without it. A state's
 * sentence about its own base does not by itself say anything about its cities',
 * counties' and transit authorities' bases:
 *
 *  - `same_share`: every local tax in the state is computed on the same share.
 *    Texas says so in the same rule.
 *  - `unstated`: the sentence speaks for the state's tax only. The share is right
 *    for a stack that IS only the state's tax; with any local tax in it, the local
 *    base is not known, and the stack refuses rather than guess it either way.
 *
 * A rule WITHOUT `localTaxes` — Georgia's, Kansas', Minnesota's and Utah's bundled
 * software maintenance, published since schema 1.x — is not applied, exactly as
 * before 2.8: it does not say what its locals do, and nothing here was reviewed
 * against applying half of it. That leaves those four states over-collecting on
 * the exempt part, the recoverable direction, and it is a known gap.
 *
 * NEAREST RUNG WINS, on the same ladder the rate itself is resolved on: a rule filed
 * at `services.digital` reaches an invoice sold at `services.digital.saas`, and one
 * filed at the leaf outranks it. Two rules on one rung are two answers and refuse.
 *
 * @internal Changes with the register's format. Applications ask the register through
 *           {@see TaxRegister}.
 */
readonly class TaxableShare
{
    /** Every local tax in the state is computed on the same share. */
    public const string SAME_SHARE = 'same_share';

    /** The rule speaks for the state's own tax only. */
    public const string UNSTATED = 'unstated';

    private function __construct(
        /** The share of the price taxed, as a percentage: `80`. */
        public BigDecimal $proportion,
        /** {@see self::SAME_SHARE} or {@see self::UNSTATED}. */
        public string $localTaxes,
        /** The category the rule is filed at, for a message a person reads. */
        public string $category,
    ) {}

    /** Whether the share is known to apply to every local tax as well. */
    public function coversLocalTaxes(): bool
    {
        return $this->localTaxes === self::SAME_SHARE;
    }

    /**
     * The share a state taxes a category on, or null where the whole price is taxed —
     * which includes a nearest rule that does not say what its local taxes do.
     *
     * @param  string  $state  The bare state code: `us:TX`.
     *
     * @throws UnresolvedTaxRule When the rule that applies cannot be read as a share.
     */
    public static function of(RegisterData $dataset, string $state, string $key, DateTimeImmutable $at): ?self
    {
        $ladder = CategoryMap::ladder($key);
        $nearest = [];
        $rung = null;

        foreach ($dataset->rulesOn($state, 'taxable_base', $at) as $rule) {
            $payload = Shape::map($rule['payload'] ?? null);

            if (($payload['component'] ?? null) !== 'supplied_item') {
                continue;
            }

            $category = Shape::text($payload['category'] ?? null);

            if ($category === null) {
                // A share of every price in the state would be a different statement
                // from any the register makes today. Where it says what its locals do,
                // it is meant to be applied, and this reader cannot place it.
                if (array_key_exists('localTaxes', $payload)) {
                    throw new UnresolvedTaxRule(sprintf('The share-of-price rule for %s names no category, which this reader cannot place on the category ladder.', $state));
                }

                continue;
            }

            $position = array_search($category, $ladder, true);

            if ($position === false || ($rung !== null && $position > $rung)) {
                continue;
            }

            if ($rung === null || $position < $rung) {
                $nearest = [];
                $rung = $position;
            }

            $nearest[] = $payload;
        }

        // Only what is published with `localTaxes` is applied. A nearer rule without
        // it governs the category and is not applied, as before schema 2.8; reaching
        // past it to a broader rule would price the supply under the wrong statute.
        $applied = array_values(array_filter($nearest, static fn (array $payload): bool => array_key_exists('localTaxes', $payload)));

        if ($applied === []) {
            return null;
        }

        $where = sprintf('%s at %s', $state, $ladder[$rung ?? 0]);

        if (count($nearest) > 1) {
            throw new UnresolvedTaxRule(sprintf('Overlapping share-of-price rules for %s.', $where));
        }

        $payload = $applied[0];
        $proportion = $payload['proportion'] ?? null;
        $localTaxes = $payload['localTaxes'];

        if (array_key_exists('decision', $payload) || ($payload['included'] ?? null) !== true) {
            throw new UnresolvedTaxRule(sprintf('The share-of-price rule for %s is not an unconditional inclusion, which is the only shape this reader prices.', $where));
        }

        if (! is_string($proportion) || ! is_numeric($proportion)) {
            throw new UnresolvedTaxRule(sprintf('The share-of-price rule for %s publishes no proportion.', $where));
        }

        $share = BigDecimal::of($proportion);

        if (! $share->isPositive() || $share->isGreaterThan(100)) {
            throw new UnresolvedTaxRule(sprintf('The share-of-price rule for %s publishes a proportion of %s%%, which is not a share of the price.', $where, $proportion));
        }

        if ($localTaxes !== self::SAME_SHARE && $localTaxes !== self::UNSTATED) {
            throw new UnresolvedTaxRule(sprintf(
                'The share-of-price rule for %s says its local taxes are "%s", which this reader does not know.',
                $where,
                is_string($localTaxes) ? $localTaxes : get_debug_type($localTaxes),
            ));
        }

        return new self($share, $localTaxes, $ladder[$rung ?? 0]);
    }
}
