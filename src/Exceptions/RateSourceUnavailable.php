<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\RateSource\ChainTaxRateSource;
use RuntimeException;

/**
 * A rate source that SHOULD have been able to answer could not — its endpoint was
 * unreachable, refused, or returned something unusable.
 *
 * This exists because `null` was carrying two meanings that need opposite handling.
 * "I have no rate for this jurisdiction" is a normal answer from a source with
 * limited scope, and {@see ChainTaxRateSource} rightly moves on to the next one.
 * "My endpoint timed out" is not an answer at all, and moving on quietly reaches
 * the static snapshot and bills from it — a rate that may be a year old, charged
 * with no more hesitation than a live one.
 *
 * It was not literally silent: the fallback carries `Confidence::Derived` rather
 * than `Authoritative`. But `Derived` is also what a correctly coarse resolution
 * looks like — a state rate where rooftop was unavailable — so an operator could
 * not tell a broken feed from a normal day, and had nothing to alert on.
 *
 * A source throws this only for OPERATIONAL failure. Having no rate for a
 * jurisdiction it does not cover stays `null`, and a single unusable value within
 * an otherwise good payload also stays `null` — that is bad data, not a broken
 * source, and it should fall through to something that can answer.
 *
 * @see TaxRateSource
 */
class RateSourceUnavailable extends RuntimeException
{
    private function __construct(
        public readonly string $source,
        public readonly string $why,
    ) {
        parent::__construct(sprintf('Tax rate source "%s" is unavailable: %s', $source, $why));
    }

    public static function transport(string $source, string $why = 'the request failed'): self
    {
        return new self($source, $why);
    }

    public static function badResponse(string $source, int $status): self
    {
        return new self($source, sprintf('the endpoint answered HTTP %d', $status));
    }

    public static function unreadable(string $source): self
    {
        return new self($source, 'the response could not be read as the expected format');
    }
}
