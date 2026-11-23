<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Contracts\SmallBusinessRules;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\InvoiceMention;
use Cbox\Tax\ValueObjects\SmallBusinessRule;
use Cbox\Tax\ValueObjects\SmallBusinessThreshold;
use DateTimeImmutable;

/**
 * The register's `small_business_exemption` rules, one per member state.
 *
 * NOT THE `threshold` RULES, though most states' figures are there too. Those say
 * when a business must register and are read by whoever monitors registration; this
 * says what an exempt seller's supply IS — exempt, under which provision, with which
 * words on the invoice, and from which supply it stops — and a state is covered only
 * once all of that has been read.
 */
readonly class RegisterSmallBusiness implements SmallBusinessRules
{
    public function __construct(private RegisterData $dataset) {}

    public function for(CountryCode $state, DateTimeImmutable $on): ?SmallBusinessRule
    {
        foreach ($this->dataset->codesForCountry($state->value, $on) as $code) {
            $rule = $this->dataset->rulesOn($code, 'small_business_exemption', $on)[0] ?? null;

            if ($rule !== null) {
                return $this->read($code, $rule);
            }
        }

        return null;
    }

    public function all(DateTimeImmutable $on): array
    {
        $out = [];
        $date = $on->format('Y-m-d');

        foreach (Shape::records($this->dataset->document('rules')['rules'] ?? null) as $rule) {
            $code = Shape::text($rule['jurisdiction'] ?? null);
            $effective = Shape::map($rule['effective'] ?? null);
            $from = Shape::text($effective['from'] ?? null);
            $until = Shape::text($effective['until'] ?? null);

            if ($code === null || Shape::text($rule['kind'] ?? null) !== 'small_business_exemption'
                || ($from !== null && $from > $date) || ($until !== null && $date > $until)) {
                continue;
            }

            $read = $this->read($code, $rule);

            if ($read !== null) {
                $out[strtoupper(substr($code, (int) strrpos($code, ':') + 1))] ??= $read;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function read(string $code, array $rule): ?SmallBusinessRule
    {
        $payload = Shape::map($rule['payload'] ?? null);
        $citation = Shape::text($payload['citation'] ?? null);
        $source = Shape::text(Shape::map($rule['provenance'] ?? null)['source'] ?? null);

        if (($payload['available'] ?? true) === false) {
            return new SmallBusinessRule(
                jurisdiction: $code,
                currency: 'EUR',
                citation: $citation,
                source: $source,
                available: false,
                unavailableBecause: Shape::text($payload['says'] ?? null),
            );
        }

        $thresholds = [];
        $currency = null;

        foreach (Shape::records($payload['thresholds'] ?? null) as $threshold) {
            $amount = Shape::text($threshold['amount'] ?? null);
            $measuredOver = Shape::text($threshold['measuredOver'] ?? null);
            $currency ??= Shape::text($threshold['currency'] ?? null);

            // A limit without its figure is not a limit of zero: the rule is not read.
            if ($amount === null || $measuredOver === null || Shape::text($threshold['currency'] ?? null) !== $currency) {
                return null;
            }

            $share = Shape::map($threshold['whenGoodsShare'] ?? null);

            $thresholds[] = new SmallBusinessThreshold(
                amount: $amount,
                measuredOver: $measuredOver,
                appliesTo: Shape::text($threshold['appliesTo'] ?? null) ?? 'all',
                firstYearOfActivity: ($threshold['firstYearOfActivity'] ?? false) === true,
                tolerance: Shape::text(Shape::map($threshold['tolerance'] ?? null)['amount'] ?? null),
                goodsShareAtLeast: Shape::text($share['atLeast'] ?? null),
                goodsShareBelow: Shape::text($share['below'] ?? null),
            );
        }

        if ($thresholds === [] || $currency === null) {
            return null;
        }

        $crossing = Shape::map($payload['crossing'] ?? null);
        $following = $crossing['excludesFollowingYears'] ?? 0;
        $union = Shape::map(Shape::map($payload['crossBorder'] ?? null)['unionTurnover'] ?? null);
        $invoice = Shape::map($payload['invoice'] ?? null);
        $mention = Shape::text($invoice['mention'] ?? null);

        return new SmallBusinessRule(
            jurisdiction: $code,
            currency: $currency,
            thresholds: $thresholds,
            unionThreshold: Shape::text($union['amount'] ?? null),
            unionCurrency: Shape::text($union['currency'] ?? null),
            mention: $mention === null ? null : new InvoiceMention('small_business_exemption', $mention, Shape::text($invoice['reference'] ?? null) ?? $citation),
            citation: $citation,
            source: $source,
            crossing: Shape::text($crossing['endsWith'] ?? null) ?? SmallBusinessRule::CROSSING_SUPPLY,
            excludesFollowingYears: is_int($following) ? $following : 0,
            exclusions: array_values(array_filter(array_map(
                static fn (array $exclusion): ?string => Shape::text($exclusion['names'] ?? null),
                Shape::records($payload['exclusions'] ?? null),
            ))),
        );
    }
}
