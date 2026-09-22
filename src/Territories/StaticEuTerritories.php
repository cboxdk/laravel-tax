<?php

declare(strict_types=1);

namespace Cbox\Tax\Territories;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\EuTerritories;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
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
 * The territories that keep their own RATES (the Azores, Madeira) are priced from
 * the REGISTER, not from here. Cadastre publishes their dated standard,
 * intermediate and reduced bands as `eu:PT:MADEIRA` and `eu:PT:AZORES`; each band
 * is paired with the mainland band of the same kind on the supply date, which is
 * what turns the mainland's 13% into Madeira's 12%. When the register cannot supply
 * them the supply refuses — it never falls back to the mainland's rates.
 *
 * What stays in this class is what the register does not publish: which postal
 * ranges belong to which territory, and which territories Article 6 places outside
 * the VAT area. Neither is a moving figure; both are requested from cadastre in
 * conformance/cadastre-feedback.md.
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
    /** Register codes for the territories that keep their own rates. */
    private const array OWN_RATES = ['PT-30' => ['eu:PT:MADEIRA', 'Madeira'], 'PT-20' => ['eu:PT:AZORES', 'Azores']];

    public function __construct(private ?RegisterDataset $register = null) {}

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
        // The islands keep their own rates but remain inside the EU VAT area —
        // a different case entirely from Spain's, and the reason this class
        // distinguishes the two rather than treating "special" as one thing.
        $code = match (true) {
            $prefix >= 9000 && $prefix <= 9400 => 'PT-30',
            $prefix >= 9500 && $prefix <= 9980 => 'PT-20',
            default => null,
        };

        return $code === null ? null : $this->ownRates($code, 'eu:PT', $at);
    }

    /**
     * A territory with its own rates, each band read from the register and keyed by
     * the mainland band of the same KIND on the same date.
     *
     * By kind, because that is how both are published — standard, intermediate,
     * reduced — and by date, because Madeira's reduced band went from 5% to 4% on
     * 1 October 2024 and a back-dated supply must take the band it was made under.
     */
    private function ownRates(string $code, string $mainland, ?DateTimeImmutable $at): EuTerritory
    {
        [$registerCode, $name] = self::OWN_RATES[$code];
        $on = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');

        $home = $this->bands($mainland, $on);
        $away = $this->bands($registerCode, $on);

        if (! isset($away['standard'])) {
            throw new UnresolvedTaxRule(sprintf(
                '%s charges its own VAT rates, and the installed register does not supply them (%s on %s). Refusing rather than charging the mainland rate.',
                $name,
                $registerCode,
                $on,
            ));
        }

        $map = [];

        foreach ($away as $kind => $percentage) {
            if (isset($home[$kind])) {
                $map[$home[$kind]] = $percentage;
            }
        }

        return EuTerritory::withOwnRates($code, $name, $away['standard'], $map);
    }

    /**
     * A jurisdiction's headline bands on a date, by kind: `['standard' => '22', ...]`.
     * Only rows with no category and no classification — the bands themselves.
     *
     * @return array<string, string>
     */
    private function bands(string $jurisdiction, string $on): array
    {
        $bands = [];

        foreach ($this->register?->ratesFor($jurisdiction) ?? [] as $rate) {
            $effective = Shape::map($rate['effective'] ?? null);
            $from = $effective['from'] ?? null;
            $until = $effective['until'] ?? null;

            if (($rate['category'] ?? null) !== null || ($rate['classification'] ?? null) !== null
                || (is_string($from) && $from > $on) || (is_string($until) && $until < $on)
                || ! is_string($rate['kind'] ?? null) || ! is_string($rate['percentage'] ?? null)) {
                continue;
            }

            $bands[$rate['kind']] = $rate['percentage'];
        }

        return $bands;
    }
}
