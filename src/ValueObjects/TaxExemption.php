<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\ExemptionType;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * A buyer's tax exemption, expressed as a native input to a {@see TaxQuery}: the
 * legal basis ({@see ExemptionType}), an opaque certificate reference, the
 * jurisdiction(s) the certificate covers, and its validity window.
 *
 * The engine deliberately does NOT capture or verify the underlying certificate —
 * that is the consumer's concern (a certificate store, expiry checks, a
 * verification service). The consumer expresses the *result* of that verification
 * as this VO, and the engine applies it deny-by-default: an exemption only exempts
 * a supply when it is valid at the time of supply AND covers the jurisdiction the
 * supply would otherwise be taxed in. An exemption for a different jurisdiction, or
 * an expired/not-yet-valid one, does not exempt.
 *
 * Coverage is matched at the granularity of the taxing jurisdiction. For a
 * sub-federal place of supply (a US state, a Canadian province) only a matching
 * `subdivisions` entry exempts — a bare country entry does NOT, because
 * exemption certificates there are issued per state/province. For a national place
 * of supply a matching `countries` entry exempts.
 */
readonly class TaxExemption
{
    /**
     * @param  list<CountryCode>  $countries  Country-level coverage (EU/national VAT jurisdictions).
     * @param  list<SubdivisionCode>  $subdivisions  Sub-federal coverage (US states, Canadian provinces).
     */
    public function __construct(
        public ExemptionType $type,
        public string $reference,
        public array $countries = [],
        public array $subdivisions = [],
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validUntil = null,
    ) {}

    /**
     * Whether this exemption both is valid at `$at` and covers `$place` — the full
     * test the engine applies before it will exempt a supply.
     */
    public function appliesTo(Jurisdiction $place, DateTimeInterface $at): bool
    {
        return $this->isValidAt($at) && $this->covers($place);
    }

    /**
     * Whether the certificate covers the taxing jurisdiction. Deny-by-default:
     * a sub-federal place requires a matching subdivision; a national place
     * requires a matching country.
     */
    public function covers(Jurisdiction $place): bool
    {
        if ($place->subdivision !== null) {
            foreach ($this->subdivisions as $subdivision) {
                if ($subdivision->equals($place->subdivision)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($this->countries as $country) {
            if ($country->equals($place->country)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the certificate is within its validity window at `$at`. An open-ended
     * bound (null) never fails; deny-by-default only bites when a bound is set and
     * `$at` falls outside it.
     */
    public function isValidAt(DateTimeInterface $at): bool
    {
        if ($this->validFrom !== null && $at < $this->validFrom) {
            return false;
        }

        if ($this->validUntil !== null && $at > $this->validUntil) {
            return false;
        }

        return true;
    }

    /** A short phrase for the assessment's human-readable reason string. */
    public function describe(): string
    {
        return sprintf('%s exemption (ref: %s)', $this->type->label(), $this->reference);
    }
}
