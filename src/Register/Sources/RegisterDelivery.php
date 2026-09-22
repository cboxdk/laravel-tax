<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\DeliveryRules;
use Cbox\Tax\Enums\DeliveryComponent;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use DateTimeImmutable;

final readonly class RegisterDelivery implements DeliveryRules
{
    public function __construct(private RegisterDataset $dataset) {}

    public function included(SubdivisionCode $state, DeliveryComponent $component, bool $goodsTaxable, DateTimeImmutable $at): ?bool
    {
        $key = $component->value.($goodsTaxable ? '_on_taxable_goods' : '_on_exempt_goods');
        $included = null;

        foreach ($this->dataset->rulesOn(UsCode::of($state), 'taxable_base', $at) as $rule) {
            $payload = Shape::map($rule['payload'] ?? null);

            if (($payload['component'] ?? null) !== $key) {
                continue;
            }

            if ($included !== null || ! is_bool($payload['included'] ?? null) || isset($payload['category']) || array_key_exists('proportion', $payload)) {
                throw new UnresolvedTaxRule('Ambiguous or unsupported delivery rule for '.$state->value.'.');
            }

            $included = $payload['included'];
        }

        return $included;
    }
}
