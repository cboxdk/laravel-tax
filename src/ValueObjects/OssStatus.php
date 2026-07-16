<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * The seller's standing under the EU One-Stop-Shop micro-business rule (Art. 59c
 * VAT Directive). Two facts decide whether cross-border B2C supplies are taxed at
 * origin or destination:
 *
 *  - `registered` — the seller has opted into (or registered for) OSS, so it
 *    charges destination VAT regardless of turnover.
 *  - `thresholdExceeded` — the combined cross-border B2C turnover exceeded the
 *    €10,000 threshold in the current OR preceding calendar year, which removes the
 *    micro-business relief and forces destination taxation.
 *
 * A micro-business below the threshold and not opted in charges its OWN (origin)
 * VAT. These are inputs the seller supplies; the engine never guesses turnover.
 */
readonly class OssStatus
{
    public function __construct(
        public bool $registered = false,
        public bool $thresholdExceeded = false,
    ) {}

    /**
     * Whether cross-border B2C supplies must be taxed at destination. True once the
     * seller has opted into OSS or crossed the €10,000 threshold; false only for a
     * below-threshold, non-opted micro-business (which sources at origin).
     */
    public function taxesAtDestination(): bool
    {
        return $this->registered || $this->thresholdExceeded;
    }
}
