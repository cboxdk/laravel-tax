<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\Jurisdiction;
use DateTimeImmutable;

/**
 * A local-authority resolver that can say when the locality it was given — a bare
 * five-digit ZIP — is split between several sets of taxing authorities.
 *
 * An optional companion to {@see LocalAuthorityResolver}, the way the category-keyed
 * and fact-aware rate sources are optional companions to theirs. A ZIP is a mail
 * route, not a tax boundary: Washington's 98001 holds Federal Way and Auburn at 10.4%
 * and unincorporated King County at 10.3%. Answered from the five digits alone, it is
 * one of those — and without this, it was priced as that one and called certain.
 */
interface ReportsSplitPostcodes
{
    /** Whether the jurisdiction's locality could be any of several authority sets. */
    public function spansSeveralSets(Jurisdiction $jurisdiction, ?DateTimeImmutable $at = null): bool;
}
