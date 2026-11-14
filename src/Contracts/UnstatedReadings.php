<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\ValueObjects\UnstatedReading;
use DateTimeImmutable;

/**
 * Where the register READ a place's law for a category and found it silent.
 *
 * A missing answer cannot tell "the law was read and says nothing about this" from
 * "nobody has read it yet", and the two call for different work: the first is the
 * place's general treatment, the second is a gap. This answers which, for a host
 * that wants to show it — a catalogue review, a coverage page.
 *
 * NEVER AN ANSWER. A reading is not a rate and not an exemption, and nothing is
 * priced from it: the rate for the category is what it would be without it. Do not
 * present one as 0% or as exempt.
 */
interface UnstatedReadings
{
    /**
     * The readings for a category in a place on a date, nearest category first: one
     * filed for a broader category covers the narrower one asked about. Empty where
     * the register records none.
     *
     * @return list<UnstatedReading>
     */
    public function for(Jurisdiction $place, string $categoryKey, ?DateTimeImmutable $at = null): array;
}
