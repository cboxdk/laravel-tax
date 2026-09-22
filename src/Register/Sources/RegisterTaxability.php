<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\CategoryKeyedTaxability;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\ThresholdRule;
use Cbox\Tax\Exceptions\UnresolvedProductTaxability;
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
final readonly class RegisterTaxability implements CategoryKeyedTaxability
{
    public function __construct(private RegisterDataset $dataset) {}

    public function determine(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        Money $amount,
        ?DateTimeImmutable $at = null,
    ): TaxDetermination {
        return $this->decide($jurisdiction, CategoryMap::keyFor($category), $category, $at);
    }

    public function determineKey(
        Jurisdiction $jurisdiction,
        string $categoryKey,
        Money $amount,
        ?DateTimeImmutable $at = null,
    ): TaxDetermination {
        $this->dataset->assertCategoryPublished($categoryKey);

        return $this->decide($jurisdiction, $categoryKey, $categoryKey, $at);
    }

    private function decide(Jurisdiction $jurisdiction, string $key, TaxClass|string $category, ?DateTimeImmutable $at): TaxDetermination
    {
        $code = $this->code($jurisdiction, $at);

        if ($code === null) {
            return TaxDetermination::taxable();
        }

        $on = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');

        $threshold = $this->priceExemption($jurisdiction, $category, $code, $key, $on);

        if ($threshold !== null) {
            return $threshold;
        }

        $exempt = $this->exemptByCategory($code, $key, $on);

        if ($exempt !== null) {
            return $exempt ? TaxDetermination::exempt() : TaxDetermination::taxable();
        }

        // NOTHING SAYS EITHER WAY ABOUT THIS CATEGORY. A jurisdiction that publishes
        // a rate at all has said that supplies there are taxed, and an exemption is
        // the thing that has to be stated — so taxable is the answer, and it is the
        // direction a customer can be refunded from.
        //
        // WHAT THIS NO LONGER DOES, and it is worth being plain about: the retired
        // us-tax-data dataset carried an explicit UNDETERMINED marker per (state,
        // category), set where its own sources disagreed, and the engine refused on
        // it rather than guessing. The register has no equivalent — its taxability
        // lives in sworn Streamlined answers keyed by classification, not in a
        // per-category verdict — so that refusal has nowhere to come from. A
        // jurisdiction the register knows NOTHING about still refuses, below.
        if ($this->dataset->ratesFor($code) !== []) {
            return TaxDetermination::taxable();
        }

        throw UnresolvedProductTaxability::for($jurisdiction, $category);
    }

    private function priceExemption(Jurisdiction $jurisdiction, TaxClass|string $category, string $code, string $key, string $on): ?TaxDetermination
    {
        foreach ($this->dataset->rulesFor($code, 'price_exemption') as $rule) {
            if (! $this->covers($rule, $on)) {
                continue;
            }

            $payload = Shape::map($rule['payload'] ?? null);

            // UP THE LADDER, as every category-keyed lookup here is. Massachusetts caps
            // `goods.clothing`; a query at `goods.clothing.childrens` is still clothing,
            // and exact matching let a child's coat through the $175 cap untaxed.
            if (! in_array(Shape::text($payload['category'] ?? null), CategoryMap::ladder($key), true)) {
                continue;
            }

            $cap = Shape::text($payload['capAmount'] ?? null);
            $currency = Shape::text($payload['capCurrency'] ?? null);
            $above = Shape::text($payload['above'] ?? null);

            // A rule that says there IS a cap and does not say what it is, or does
            // not say what happens above it, cannot be priced. Skipping it would
            // fall through to fully taxable — safe for the customer, and a broken
            // record nobody ever finds. Refusing puts it in front of somebody.
            if ($cap === null || $currency === null || $above === null) {
                throw UnresolvedProductTaxability::conditional($jurisdiction, $category);
            }

            return TaxDetermination::belowThreshold(
                (int) round(((float) $cap) * 100),
                $above === 'whole_item_taxable' ? ThresholdRule::Cliff : ThresholdRule::ExcessTaxable,
                $currency,
            );
        }

        return null;
    }

    /**
     * True where the category is published as exempt or zero-rated, false where it
     * is published as charged, and NULL where nothing published says either.
     */
    private function exemptByCategory(string $code, string $key, string $on): ?bool
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

        return null;
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
