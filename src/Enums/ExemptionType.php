<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * The legal basis for a buyer's tax exemption, as asserted by a certificate the
 * consumer has captured and verified. The engine does not verify the certificate
 * — it records which kind of exemption was applied so the assessment's audit
 * trail names the basis.
 *
 * `Resale` is the common US case (a reseller's permit — the buyer will re-sell
 * and collect tax downstream); `Nonprofit` and `Government` cover exempt-entity
 * purchases; `Other` is an escape hatch for a jurisdiction-specific basis the
 * consumer names in the reference.
 */
enum ExemptionType: string
{
    case Resale = 'resale';
    case Nonprofit = 'nonprofit';
    case Government = 'government';
    case Other = 'other';

    /** A short human-readable label for the assessment's reason string. */
    public function label(): string
    {
        return match ($this) {
            self::Resale => 'resale',
            self::Nonprofit => 'nonprofit',
            self::Government => 'government',
            self::Other => 'other',
        };
    }
}
