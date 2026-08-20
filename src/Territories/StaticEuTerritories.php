<?php

declare(strict_types=1);

namespace Cbox\Tax\Territories;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\EuTerritories;
use Cbox\Tax\ValueObjects\EuTerritory;
use DateTimeImmutable;

/**
 * The shipped snapshot of the EU's special VAT territories, matched on postal code.
 *
 * Postal code because nothing else is available. The addressing reference data
 * carries no subdivisions at all for Portugal, Finland, France or Greece, so the
 * Azores, Madeira, Åland and Corsica cannot be named that way — while each of
 * these territories has had its own postal range for decades.
 *
 * Territories excluded from the EU VAT area are listed in Article 6 of the VAT
 * Directive (2006/112/EC) and have not changed in years, which is why a snapshot
 * is honest here in a way a rate snapshot would not be: rates move, the map does
 * not.
 *
 * The territories that keep their own RATES (the Azores, Madeira) carry every
 * level, keyed by the mainland rate each one replaces. That reverses an earlier
 * decision — "their reduced bands belong in a rate source, not here" — which was
 * made on the assumption that a rate source would carry them. It carries half:
 * TEDB returns the territorial STANDARD rates under its REGION heading (16%
 * "Azores Autonomous Region", 22% "Madeira Autonomous Region") but not the
 * reduced and intermediate levels — and those are the ones a grocery or a book
 * line needs. Before they were sourced, a Madeira grocery line was priced at the
 * mainland's 6% with a caveat rather than the 5% (now 4%) that actually applied.
 * The figures come from the Portuguese tax authority's Ofício Circulado
 * n.º 25045 (2024-12-06) and its annex table.
 *
 * Two territory families are ABSENT on purpose, not as gaps. Corsica's special
 * rates are enumerated per operation rather than per level, so the substitution
 * mechanism above cannot carry them — the mainland fallback only ever
 * over-charges there (docs/decisions/corsica.md). The Greek islands with 30%
 * reduced rates and Austria's Jungholz/Mittelberg key on where the SELLER is
 * established, so for the cross-border supplies this resolver prices, the
 * national rate IS the right answer and an entry would under-charge
 * (docs/decisions/seller-scoped-territories.md).
 *
 * Postal ranges, and where they come from:
 *
 *  - Spain: Canary Islands 35xxx (Las Palmas) and 38xxx (Santa Cruz de Tenerife);
 *    Ceuta 51xxx; Melilla 52xxx. Ceuta and Melilla have their own province
 *    prefixes precisely because they are not ordinary provinces.
 *  - Portugal: Madeira 9000–9400, Azores 9500–9980.
 *  - Finland: Åland 22xxx.
 *  - Germany: Büsingen 78266, Heligoland 27498 — single municipalities, single codes.
 *  - Italy: Livigno 23041, Campione d'Italia 22061.
 *  - Greece: Mount Athos 63086.
 *
 * France's overseas départements need no entry: Guadeloupe, Martinique, French
 * Guiana, Réunion and Mayotte each carry their own ISO 3166-1 country code, and
 * the geo repository already resolves them as non-EU.
 */
readonly class StaticEuTerritories implements EuTerritories
{
    public function for(CountryCode $country, ?string $postalCode, ?DateTimeImmutable $at = null): ?EuTerritory
    {
        $digits = $postalCode === null ? null : preg_replace('/\D/', '', $postalCode);

        if ($digits === null || $digits === '') {
            // No postcode is not "mainland". It is an address we cannot place, and
            // the caller has to know that rather than be handed the national rate
            // for a delivery that might be going to Tenerife.
            return null;
        }

        return match ($country->value) {
            'ES' => $this->spain($digits),
            'PT' => $this->portugal($digits, $at),
            'FI' => str_starts_with($digits, '22')
                ? EuTerritory::outsideVatArea('AX', 'Åland Islands', 'Åland has its own VAT-free status under the Act of Accession')
                : null,
            'DE' => match (substr($digits, 0, 5)) {
                '78266' => EuTerritory::outsideVatArea('DE-BUS', 'Büsingen am Hochrhein', 'Swiss VAT applies'),
                '27498' => EuTerritory::outsideVatArea('DE-HEL', 'Heligoland', 'no VAT is levied'),
                default => null,
            },
            'IT' => match (substr($digits, 0, 5)) {
                '23041' => EuTerritory::outsideVatArea('IT-LIV', 'Livigno', 'no VAT is levied'),
                '22061' => EuTerritory::outsideVatArea('IT-CAM', "Campione d'Italia", 'Swiss VAT applies'),
                default => null,
            },
            'GR' => substr($digits, 0, 5) === '63086'
                ? EuTerritory::outsideVatArea('GR-ATH', 'Mount Athos', 'the Autonomous Monastic State is outside the VAT area')
                : null,
            default => null,
        };
    }

    private function spain(string $digits): ?EuTerritory
    {
        return match (substr($digits, 0, 2)) {
            '35', '38' => EuTerritory::outsideVatArea('ES-CN', 'Canary Islands', 'IGIC (Impuesto General Indirecto Canario)'),
            '51' => EuTerritory::outsideVatArea('ES-CE', 'Ceuta', 'IPSI (Impuesto sobre la Producción, los Servicios y la Importación)'),
            '52' => EuTerritory::outsideVatArea('ES-ML', 'Melilla', 'IPSI (Impuesto sobre la Producción, los Servicios y la Importación)'),
            default => null,
        };
    }

    private function portugal(string $digits, ?DateTimeImmutable $at): ?EuTerritory
    {
        $prefix = (int) substr($digits, 0, 4);

        // The islands keep their own rates but remain inside the EU VAT area —
        // a different case entirely from Spain's, and the reason this class
        // distinguishes the two rather than treating "special" as one thing.
        if ($prefix >= 9000 && $prefix <= 9400) {
            // Madeira's reduced rate went from 5% to 4% on 2024-10-01 (Decreto
            // Legislativo Regional n.º 6/2024/M, art. 21.º, effective under art.
            // 121.º n.º 2). A back-dated supply must not take today's.
            $on = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');

            return EuTerritory::withOwnRates('PT-30', 'Madeira', '22', [
                '23' => '22',
                '13' => '12',
                '6' => $on >= '2024-10-01' ? '4' : '5',
            ]);
        }

        if ($prefix >= 9500 && $prefix <= 9980) {
            // A flat 30% cut of the national levels since 2021-07-01 (Decreto
            // Legislativo Regional n.º 15-A/2021/A), which turns 6/13/23 into
            // 4/9/16.
            return EuTerritory::withOwnRates('PT-20', 'Azores', '16', [
                '23' => '16',
                '13' => '9',
                '6' => '4',
            ]);
        }

        return null;
    }
}
