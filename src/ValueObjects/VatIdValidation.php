<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * The result of validating a business tax ID. `conclusive` is the load-bearing
 * field: it is `false` when the validation service could not give a definitive
 * answer (unreachable, timed out, "unable to verify"). Reverse-charge zero-rating
 * legally hinges on a validated ID, so it is only safe on a **conclusive valid**
 * result — a failure must fall back to charging tax, never silently zero-rate.
 *
 * `consultationReference` is the proof-of-check identifier some services return
 * (VIES consultation number, HMRC consultation reference); record it for audit.
 */
readonly class VatIdValidation
{
    public function __construct(
        public bool $valid,
        public bool $conclusive,
        public ?string $name = null,
        public ?string $address = null,
        public ?string $consultationReference = null,
        public string $source = '',
    ) {}

    public static function valid(string $source, ?string $name = null, ?string $address = null, ?string $consultationReference = null): self
    {
        return new self(true, true, $name, $address, $consultationReference, $source);
    }

    public static function invalid(string $source): self
    {
        return new self(false, true, source: $source);
    }

    /** The service could not determine validity (outage / unable to verify). */
    public static function inconclusive(string $source): self
    {
        return new self(false, false, source: $source);
    }

    /** Reverse-charge zero-rating is only safe on a conclusive valid result. */
    public function permitsReverseCharge(): bool
    {
        return $this->valid && $this->conclusive;
    }
}
