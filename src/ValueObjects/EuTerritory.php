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
    ) {}

    public static function outsideVatArea(string $code, string $name, string $ownTaxName): self
    {
        return new self($code, $name, true, null, $ownTaxName);
    }

    public static function withOwnRate(string $code, string $name, string $standardRate): self
    {
        return new self($code, $name, false, $standardRate);
    }
}
