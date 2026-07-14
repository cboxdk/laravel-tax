<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * How trustworthy the jurisdiction/rate resolution behind an assessment is. This
 * is recorded per assessment so a coarse fallback (e.g. a state-level rate where
 * rooftop geocoding was unavailable) is never mistaken for an authoritative one.
 */
enum Confidence: string
{
    case Authoritative = 'authoritative';
    case Derived = 'derived';
    case LowConfidence = 'low_confidence';
}
