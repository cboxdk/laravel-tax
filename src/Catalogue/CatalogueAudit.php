<?php

declare(strict_types=1);

namespace Cbox\Tax\Catalogue;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\ProductCatalogue;
use Cbox\Tax\Register\Reader\CategoryMap;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\ValueObjects\CatalogueFinding;
use DateTimeImmutable;

/**
 * Which products in a catalogue get a conditional answer in which markets — the
 * question a seller asks when it opens a market, asked once rather than at every
 * checkout.
 *
 * A PRODUCT IS DESCRIBED ONCE, FOR EVERY MARKET. A seller creating a product does not
 * know every country it will be sold into, and asking it per country is the mess this
 * exists to avoid. The product carries a class or key, ideally its commodity code — it
 * needs one for customs anyway — and whatever facts a code cannot say. This reads that
 * description against each market the seller names and reports only where it falls
 * short: the conditions left open, whether the code would close them, and which facts
 * would.
 *
 * Nothing here blocks a sale. An open condition is still priced at the published rate,
 * flagged; this is how the flag gets found before the first invoice rather than after
 * the hundredth.
 */
final readonly class CatalogueAudit
{
    public function __construct(
        private ProductCatalogue $catalogue,
        private RegisterRateSource $rates,
    ) {}

    /**
     * @param  iterable<string>  $itemCodes
     * @param  iterable<Jurisdiction>  $markets
     * @return list<CatalogueFinding>
     */
    public function audit(iterable $itemCodes, iterable $markets, ?DateTimeImmutable $at = null): array
    {
        $markets = is_array($markets) ? array_values($markets) : iterator_to_array($markets, false);
        $findings = [];

        foreach ($itemCodes as $itemCode) {
            $mapping = $this->catalogue->find($itemCode);

            if ($mapping === null) {
                $findings[] = CatalogueFinding::unmapped($itemCode);

                continue;
            }

            $key = $mapping->categoryKey ?? CategoryMap::keyFor($mapping->class);
            $source = $this->rates->withFacts($mapping->facts);

            foreach ($markets as $market) {
                $open = $source->unsettledConditions($market, $key, $mapping->commodityCode, $at);

                if ($open !== []) {
                    $findings[] = new CatalogueFinding($itemCode, $market, $open);
                }
            }
        }

        return $findings;
    }
}
