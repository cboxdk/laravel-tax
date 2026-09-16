<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Tax\Contracts\UsTaxFacts;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Register\Reader\CategoryMap;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use DateTimeImmutable;

/**
 * The four facts the US sales-tax regime asks for, out of the register's rules.
 *
 * Each is a rule kind the register publishes with its own citation: `holiday`,
 * `remote_seller_election`, `marketplace_facilitator`, and the state's own standard
 * rate. Every one is dated, and every one is answered by CONTAINMENT rather than by
 * taking the newest — a holiday that ran last July must not price a sale this
 * September, and the register keeps both.
 */
final readonly class RegisterUsFacts implements UsTaxFacts
{
    public function __construct(private RegisterDataset $dataset) {}

    public function stateRatePercent(string $state, ?DateTimeImmutable $at = null): ?string
    {
        $on = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');

        foreach ($this->dataset->ratesFor($this->code($state)) as $rate) {
            if (($rate['kind'] ?? null) !== 'standard' || ($rate['category'] ?? null) !== null) {
                continue;
            }

            if ($this->covers($rate, $on)) {
                return Shape::text($rate['percentage'] ?? null);
            }
        }

        return null;
    }

    /**
     * @return array{name: string, cap: int, capInclusive: bool}|null
     */
    public function salesTaxHoliday(string $state, string $class, string $on): ?array
    {
        $category = $this->categoryOf($class);

        foreach ($this->dataset->rulesFor($this->code($state), 'holiday') as $rule) {
            if (! $this->covers($rule, $on)) {
                continue;
            }

            $payload = Shape::map($rule['payload'] ?? null);

            if (Shape::text($payload['category'] ?? null) !== $category) {
                continue;
            }

            $amount = Shape::text($payload['capAmount'] ?? null);

            if ($amount === null) {
                continue;
            }

            return [
                'name' => Shape::text($payload['name'] ?? null) ?? 'Sales tax holiday',
                // Minor units, because the engine compares against Money. The register
                // publishes the figure as a decimal string precisely so nobody parses
                // it into a float on the way through.
                'cap' => (int) round(((float) $amount) * 100),
                // `capIsExclusive` settles whether an item priced EXACTLY at the
                // ceiling is exempt. One word in a statute, one boundary case, and
                // read wrong in four states by the compilation this replaced. Null
                // means the holiday has no cap to draw a line in, so nothing is
                // asserted — inclusive is the reading that exempts, which is the
                // direction a customer is not overcharged in.
                'capInclusive' => ($payload['capIsExclusive'] ?? null) !== true,
            ];
        }

        return null;
    }

    /**
     * @return array{program: string, mechanic: string, ratePercent: string, statute: string}|null
     */
    public function remoteSellerElection(string $state, string $on): ?array
    {
        foreach ($this->dataset->rulesFor($this->code($state), 'remote_seller_election') as $rule) {
            if (! $this->covers($rule, $on)) {
                continue;
            }

            $payload = Shape::map($rule['payload'] ?? null);

            $program = Shape::text($payload['program'] ?? null);
            $mechanic = Shape::text($payload['mechanic'] ?? null);
            $rate = Shape::text($payload['ratePercent'] ?? null);

            if ($program === null || $mechanic === null || $rate === null) {
                continue;
            }

            return [
                'program' => $program,
                'mechanic' => $mechanic,
                'ratePercent' => $rate,
                'statute' => Shape::text($payload['statute'] ?? null) ?? '',
            ];
        }

        return null;
    }

    public function marketplaceFacilitatorFrom(string $state): ?string
    {
        foreach ($this->dataset->rulesFor($this->code($state), 'marketplace_facilitator') as $rule) {
            if (Shape::map($rule['payload'] ?? null)['platformOwes'] ?? null) {
                return Shape::text(Shape::map($rule['effective'] ?? null)['from'] ?? null);
            }
        }

        return null;
    }

    /**
     * `until` is INCLUSIVE — a holiday running until the 19th applies on the 19th —
     * and a null end means still in force, not unknown.
     *
     * @param  array<string, mixed>  $dated
     */
    private function covers(array $dated, string $on): bool
    {
        $effective = Shape::map($dated['effective'] ?? null);
        $from = Shape::text($effective['from'] ?? null);
        $until = Shape::text($effective['until'] ?? null);

        return ! (($from !== null && $from > $on) || ($until !== null && $until < $on));
    }

    private function categoryOf(string $class): ?string
    {
        $case = TaxClass::tryFrom($class);

        return $case === null ? null : CategoryMap::keyFor($case);
    }

    /** `US-KS` as the regime holds it, `us:KS` as the register addresses it. */
    private function code(string $state): string
    {
        return str_starts_with($state, 'US-') ? 'us:'.substr($state, 3) : $state;
    }
}
