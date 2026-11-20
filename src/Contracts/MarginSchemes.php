<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\MarginGoods;
use Cbox\Tax\Enums\MarginMethod;
use Cbox\Tax\ValueObjects\MarginSchemeRule;
use DateTimeImmutable;

/**
 * What a state's margin scheme says for a class of goods and a method, on a date.
 * Null where the register publishes no such scheme there: not "no scheme exists",
 * only that nothing read says so, which the engine refuses on.
 */
interface MarginSchemes
{
    public function for(CountryCode $state, MarginGoods $goods, MarginMethod $method, DateTimeImmutable $on): ?MarginSchemeRule;

    /**
     * The states the release publishes a scheme for, with what each rule still lacks.
     *
     * @return array<string, array{methods: list<string>, goods: list<string>, computable: bool}>
     */
    public function coverage(DateTimeImmutable $on): array;
}
