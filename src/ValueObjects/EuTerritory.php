<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * A part of an EU member state that its national VAT rules do not simply apply to.
 *
 * There are two kinds and they fail in completely different ways.
 *
 * **Own rates.** The Azores charge 16% where mainland Portugal charges 23%; Madeira
 * 22%. Getting this wrong is a rate error — a percentage or two, on every invoice
 * into the region, forever.
 *
 * **Outside the VAT area altogether.** The Canary Islands, Ceuta, Melilla, Åland,
 * Livigno, Büsingen, Heligoland, Campione d'Italia and Mount Athos are in the EU
 * but not in its VAT territory. A supply into one of them from a member state is
 * an EXPORT, not a domestic sale. This is not a rate error, it is the wrong tax:
 * charging 21% Spanish VAT on a delivery to Tenerife invents a liability, and the
 * customer separately owes IGIC that nobody collected.
 *
 * The second kind is why this is modelled as territory and not as a rate override.
 * A rate table cannot express "this is not our tax".
 */
readonly class EuTerritory
{
    public function __construct(
        public string $code,
        public string $name,
        /**
         * Whether the territory lies outside the EU VAT area. When true, the rates
         * below are meaningless and MUST NOT be used — the supply is not EU VAT.
         */
        public bool $outsideVatArea = false,
        /** The territory's own standard rate, as a percentage string. */
        public ?string $standardRate = null,
        /**
         * The tax that applies there instead, named for a human. We do not model
         * it, and saying which one it is beats an unexplained refusal.
         */
        public ?string $ownTaxName = null,
        /**
         * The territory's rate at each MAINLAND level, keyed by the mainland
         * percentage it replaces — `['23' => '22', '13' => '12', '6' => '4']`.
         *
         * Keyed that way because that is the only thing the engine knows at the
         * point of substitution. Portugal's CIVA sets three levels and the regional
         * assemblies set their own value for each; a supply does not carry "this is
         * the intermediate band", it carries the rate the source returned. Looking
         * that rate up is what turns Madeira's 13% into 12% rather than leaving the
         * mainland's on the invoice.
         *
         * Empty means only the standard rate is known, and a reduced supply keeps
         * the mainland's band with a caveat — which is what shipped before these
         * figures were sourced.
         *
         * PHP casts a numeric string key to an int, so the map is keyed on
         * `array-key` rather than pretending otherwise — look up with the
         * percentage string and let the cast happen consistently on both sides.
         *
         * @var array<array-key, string>
         */
        public array $rates = [],
    ) {}

    public static function outsideVatArea(string $code, string $name, string $ownTaxName): self
    {
        return new self($code, $name, true, null, $ownTaxName);
    }

    public static function withOwnRate(string $code, string $name, string $standardRate): self
    {
        return new self($code, $name, false, $standardRate);
    }

    /**
     * A territory whose rate is known at every mainland level.
     *
     * @param  array<array-key, string>  $rates  mainland percentage => territory percentage
     */
    public static function withOwnRates(string $code, string $name, string $standardRate, array $rates): self
    {
        return new self($code, $name, false, $standardRate, null, $rates);
    }

    /**
     * This territory's rate for a supply the mainland taxes at `$mainland`, or null
     * when nothing is known at that level.
     */
    public function rateFor(string $mainland): ?string
    {
        return $this->rates[$mainland] ?? null;
    }
}
