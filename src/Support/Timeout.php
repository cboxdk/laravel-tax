<?php

declare(strict_types=1);

namespace Cbox\Tax\Support;

/**
 * Reads a timeout from config. Every outbound call this package makes carries an
 * explicit timeout; a key that is missing (a config published by an older release),
 * empty, zero or not a number falls back to the documented default rather than to
 * the HTTP client's 30 s — or to "wait forever".
 */
class Timeout
{
    public static function seconds(mixed $configured, float $default): float
    {
        return is_numeric($configured) && (float) $configured > 0 ? (float) $configured : $default;
    }
}
