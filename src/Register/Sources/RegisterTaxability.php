<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\ThresholdRule;
use Cbox\Tax\Register\Reader\CategoryMap;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\TaxDetermination;
use DateTimeImmutable;

/**
 * Whether a supply is taxable where it lands, from the register's own facts.
 *
 * Three things can answer, and they are asked in this order because a narrower one
 * must not be hidden by a broader:
 *
 *  1. **A standing price exemption.** Massachusetts exempts clothing under $175 and
 *     taxes only the excess; New York exempts under $110 and taxes the whole garment
 *     once it reaches the line. Carried as a bare figure those are one field with
 *     opposite meanings, and either reading prints a plausible invoice for the
 *     other's state — so `above` is read, never assumed.
 *  2. **A published zero or exemption on the category**, which is a fact about the
 *     supply rather than its price.
 *  3. **Taxable**, which is what everything else is.
 *
 * `zero` and `exempt` both answer exempt HERE, because both are 0% to a price. The
 * difference between them — zero-rating preserves the right to deduct input tax,
 * exemption removes it — is a fact about the supplier's return, not about what the
 * customer is charged, and it survives in the rate's own provenance.
 */
final readonly class RegisterTaxability implements ProductTaxability
{
    public function __construct(private RegisterDataset $dataset) {}

    public function determine(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        Money $amount,
        ?DateTimeImmutable $at = null,
    ): TaxDetermination {
        $code = $this->code($jurisdiction, $at);

        if ($code === null) {
            return TaxDetermination::taxable();
        }

        $key = CategoryMap::keyFor($category);
        $on = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');

        $threshold = $this->priceExemption($code, $key, $on);

        if ($threshold !== null) {
            return $threshold;
        }

        return $this->exemptByCategory($code, $key, $on)
            ? TaxDetermination::exempt()
            : TaxDetermination::taxable();
    }

    private function priceExemption(string $code, string $key, string $on): ?TaxDetermination
    {
        foreach ($this->dataset->rulesFor($code, 'price_exemption') as $rule) {
            if (! $this->covers($rule, $on)) {
                continue;
            }

            $payload = Shape::map($rule['payload'] ?? null);

            if (Shape::text($payload['category'] ?? null) !== $key) {
                continue;
            }

            $cap = Shape::text($payload['capAmount'] ?? null);
            $currency = Shape::text($payload['capCurrency'] ?? null);

            if ($cap === null || $currency === null) {
                continue;
            }

            return TaxDetermination::belowThreshold(
                (int) round(((float) $cap) * 100),
                Shape::text($payload['above'] ?? null) === 'whole_item_taxable'
                    ? ThresholdRule::Cliff
                    : ThresholdRule::ExcessTaxable,
                $currency,
            );
        }

        return null;
    }

    private function exemptByCategory(string $code, string $key, string $on): bool
    {
        $rates = $this->dataset->ratesFor($code);

        foreach (CategoryMap::ladder($key) as $rung) {
            $atRung = [];

            foreach ($rates as $rate) {
                if (($rate['category'] ?? null) === $rung && $this->covers($rate, $on)) {
                    $atRung[] = $rate;
                }
            }

            if ($atRung === []) {
                continue;
            }

            // The first rung that says anything settles it. If ANY live record there
            // charges something, the supply is taxable — an exemption sitting beside
            // a rate is a scope the register expresses through classification, and
            // reading it as a blanket exemption would zero-rate the whole category.
            foreach ($atRung as $rate) {
                if (! in_array($rate['kind'] ?? null, ['zero', 'exempt'], true)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $dated
     */
    private function covers(array $dated, string $on): bool
    {
        $effective = Shape::map($dated['effective'] ?? null);
        $from = Shape::text($effective['from'] ?? null);
        $until = Shape::text($effective['until'] ?? null);

        return ! (($from !== null && $from > $on) || ($until !== null && $until < $on));
    }

    private function code(Jurisdiction $jurisdiction, ?DateTimeImmutable $at): ?string
    {
        if ($jurisdiction->subdivision !== null && $jurisdiction->country->value === 'US') {
            return UsCode::of($jurisdiction->subdivision);
        }

        foreach ($this->dataset->codesForCountry($jurisdiction->country->value, $at) as $code) {
            if ($this->dataset->carries($code)) {
                return $code;
            }
        }

        return null;
    }
}
