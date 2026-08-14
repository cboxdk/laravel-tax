<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use DateTimeImmutable;

/**
 * The bound-by-default {@see LocalAuthorityResolver}: it answers for nothing.
 *
 * A null object rather than a nullable dependency, so the rate source has one code
 * path instead of two and the seam is exercised by every test that runs without a
 * host resolver — which is all of them.
 *
 * Deferring is the correct default, not a placeholder. This package ships no
 * credentials for any state portal and will not guess an authority it cannot
 * resolve; a host that has better resolution binds its own over this one.
 */
readonly class DefersLocalAuthorities implements LocalAuthorityResolver
{
    /** @return list<string>|null */
    public function authoritiesFor(Jurisdiction $jurisdiction, ?DateTimeImmutable $at = null): ?array
    {
        return null;
    }
}
