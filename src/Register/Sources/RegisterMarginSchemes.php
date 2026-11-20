<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\MarginSchemes;
use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Enums\MarginGoods;
use Cbox\Tax\Enums\MarginMethod;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\InvoiceMention;
use Cbox\Tax\ValueObjects\MarginSchemeRule;
use DateTimeImmutable;

/**
 * The register's `margin_scheme` rules: one per state, class of goods and basis.
 *
 * The rate the margin bears, what an export does and the invoice wording are read from
 * the rule and never defaulted. A release from before the register published them
 * carries the scheme without them, and the answer is then a refusal that says to sync.
 */
readonly class RegisterMarginSchemes implements MarginSchemes
{
    public function __construct(private RegisterData $dataset) {}

    public function for(CountryCode $state, MarginGoods $goods, MarginMethod $method, DateTimeImmutable $on): ?MarginSchemeRule
    {
        foreach ($this->dataset->codesForCountry($state->value, $on) as $code) {
            foreach ($this->dataset->rulesOn($code, 'margin_scheme', $on) as $rule) {
                $payload = Shape::map($rule['payload'] ?? null);

                if (Shape::text($payload['appliesTo'] ?? null) === $goods->value && Shape::text($payload['basis'] ?? null) === $method->basis()) {
                    return $this->read($code, $rule, $payload);
                }
            }
        }

        return null;
    }

    public function coverage(DateTimeImmutable $on): array
    {
        $out = [];

        foreach (Shape::records($this->dataset->document('rules')['rules'] ?? null) as $rule) {
            $code = Shape::text($rule['jurisdiction'] ?? null);

            if ($code === null || Shape::text($rule['kind'] ?? null) !== 'margin_scheme' || ! $this->inForce($rule, $on)) {
                continue;
            }

            $payload = Shape::map($rule['payload'] ?? null);
            $country = strtoupper(substr($code, (int) strrpos($code, ':') + 1));
            $read = $this->read($code, $rule, $payload);
            $method = Shape::text($payload['basis'] ?? null) === 'margin' ? MarginMethod::Individual->value : MarginMethod::Global->value;
            $goods = Shape::text($payload['appliesTo'] ?? null) ?? '';

            $out[$country] ??= ['methods' => [], 'goods' => [], 'computable' => true];
            $out[$country]['methods'] = array_values(array_unique([...$out[$country]['methods'], $method]));
            $out[$country]['goods'] = array_values(array_unique([...$out[$country]['goods'], $goods]));
            $out[$country]['computable'] = $out[$country]['computable'] && $read->rate !== null && $read->export !== null;
        }

        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function inForce(array $rule, DateTimeImmutable $on): bool
    {
        $effective = Shape::map($rule['effective'] ?? null);
        $date = $on->format('Y-m-d');
        $from = Shape::text($effective['from'] ?? null);
        $until = Shape::text($effective['until'] ?? null);

        return ($from === null || $from <= $date) && ($until === null || $date <= $until);
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $payload
     */
    private function read(string $code, array $rule, array $payload): MarginSchemeRule
    {
        $excluded = [];
        $limit = null;
        $limitCurrency = null;

        foreach (Shape::records($payload['conditions'] ?? null) as $condition) {
            $kind = Shape::text($condition['kind'] ?? null);

            if ($kind === 'excludes') {
                foreach ((array) ($condition['codes'] ?? []) as $heading) {
                    if (is_string($heading) && $heading !== '') {
                        $excluded[] = $heading;
                    }
                }
            }

            // A cap on what the item may have cost: Germany's EUR 750 for the Gesamtdifferenz.
            if ($kind === 'quantity_limit' && Shape::text($condition['operator'] ?? null) === 'at_most'
                && preg_match('/^[A-Z]{3}$/', Shape::text($condition['unit'] ?? null) ?? '') === 1) {
                $limit = Shape::text($condition['amount'] ?? null);
                $limitCurrency = Shape::text($condition['unit'] ?? null);
            }
        }

        $rate = Shape::map($payload['rate'] ?? null);
        $export = Shape::map($payload['export'] ?? null);
        $invoice = Shape::map($payload['invoice'] ?? null);
        $mention = Shape::text($invoice['mention'] ?? null);

        return new MarginSchemeRule(
            jurisdiction: $code,
            rate: Shape::text($rate['applies'] ?? null),
            export: Shape::text($export['treatment'] ?? null),
            mention: $mention === null ? null : new InvoiceMention('margin_scheme', $mention, Shape::text($invoice['reference'] ?? null)),
            excludedCodes: array_values(array_unique($excluded)),
            purchasePriceLimit: $limit,
            purchasePriceLimitCurrency: $limitCurrency,
            source: Shape::text(Shape::map($rule['provenance'] ?? null)['source'] ?? null),
            exportFrom: Shape::text($export['from'] ?? null),
        );
    }
}
