<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Brick\Math\RoundingMode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\RoundingRules;
use Cbox\Tax\Enums\RoundingScope;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\TaxRounding;
use DateTimeImmutable;

readonly class RegisterRounding implements RoundingRules
{
    public function __construct(private RegisterDataset $dataset) {}

    public function for(SubdivisionCode $state, DateTimeImmutable $at): ?TaxRounding
    {
        $rules = $this->dataset->rulesOn(UsCode::of($state), 'rounding', $at);

        if ($rules === []) {
            if ($this->dataset->rulesFor(UsCode::of($state), 'rounding') !== []) {
                throw new UnresolvedTaxRule('No rounding policy covers '.$state->value.' on '.$at->format('Y-m-d').'.');
            }

            return null;
        }

        if (count($rules) !== 1) {
            throw new UnresolvedTaxRule('Overlapping rounding rules for '.$state->value.'.');
        }

        $payload = Shape::map($rules[0]['payload'] ?? null);
        $method = match ($payload['method'] ?? null) {
            'half_up' => RoundingMode::HalfUp,
            'up' => RoundingMode::Up,
            default => throw new UnresolvedTaxRule('Unsupported rounding method for '.$state->value.'.'),
        };
        $scope = RoundingScope::tryFrom(Shape::text($payload['appliesTo'] ?? null) ?? '');
        $places = $payload['places'] ?? null;
        $aggregate = $payload['aggregatesLocal'] ?? null;

        if ($scope === null || ! is_int($places) || ($aggregate !== null && ! is_bool($aggregate))) {
            throw new UnresolvedTaxRule('Incomplete or unsupported rounding policy for '.$state->value.'.');
        }

        return new TaxRounding($method, $places, $scope, $aggregate);
    }
}
