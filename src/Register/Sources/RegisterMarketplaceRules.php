<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\MarketplaceRules;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use DateTimeImmutable;

/**
 * The register's `marketplace_facilitator` rules, for every jurisdiction outside the
 * United States (whose states are read by the US regime's own facts). Published
 * today for the UK, Switzerland, Australia, Japan, Korea, Mexico and a dozen more.
 */
final readonly class RegisterMarketplaceRules implements MarketplaceRules
{
    public function __construct(private RegisterDataset $dataset) {}

    public function platformOwes(CountryCode $country, DateTimeImmutable $on): bool
    {
        foreach ($this->dataset->codesForCountry($country->value, $on) as $code) {
            foreach ($this->dataset->rulesOn($code, 'marketplace_facilitator', $on) as $rule) {
                if ((Shape::map($rule['payload'] ?? null)['platformOwes'] ?? null) === true) {
                    return true;
                }
            }
        }

        return false;
    }
}
