<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\MarketplaceRules;
use Cbox\Tax\Enums\MarketplaceLiability;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use DateTimeImmutable;

/**
 * The register's `marketplace_facilitator` rules, for every jurisdiction outside the
 * United States (whose states are read by the US regime's own facts). Published
 * today for the UK, Switzerland, Australia, Japan, Korea, Mexico and a dozen more.
 */
readonly class RegisterMarketplaceRules implements MarketplaceRules
{
    public function __construct(private RegisterDataset $dataset) {}

    /**
     * A MANDATE THAT NAMES CONDITIONS IS NOT A MANDATE OVER EVERY SALE. Schema 2.6
     * types the conditions that were prose until now: the 26 member states'
     * Article 14a rules each reach an imported consignment worth at most EUR 150, or
     * goods within the Community sold by a seller established outside it to a
     * customer who is not a taxable person. Read as unconditional they deem the
     * platform liable on sales the Directive leaves with the seller — so a
     * conditioned rule is reported as conditioned, and the caller is told.
     */
    public function liability(CountryCode $country, DateTimeImmutable $on): MarketplaceLiability
    {
        $conditioned = false;

        foreach ($this->codes($country, $on) as $code) {
            foreach ($this->dataset->rulesOn($code, 'marketplace_facilitator', $on) as $rule) {
                $payload = Shape::map($rule['payload'] ?? null);

                if (($payload['platformOwes'] ?? null) !== true) {
                    continue;
                }

                if (Shape::records($payload['conditions'] ?? null) === []) {
                    return MarketplaceLiability::PlatformOwes;
                }

                $conditioned = true;
            }
        }

        return $conditioned ? MarketplaceLiability::Conditioned : MarketplaceLiability::SellerCollects;
    }

    /**
     * The member's own code, then the regime it belongs to. Article 14a binds every
     * member state, and the register can hold it once at the Union (`eu`) rather than
     * twenty-seven times; read only by member, that rule was invisible and every EU
     * sale through a platform answered as the seller's to collect.
     *
     * @return list<string>
     */
    private function codes(CountryCode $country, DateTimeImmutable $on): array
    {
        $codes = [];

        foreach ($this->dataset->codesForCountry($country->value, $on) as $code) {
            $codes[] = $code;
            $regime = strstr($code, ':', true);

            if ($regime !== false) {
                $codes[] = $regime;
            }
        }

        return array_values(array_unique($codes));
    }
}
