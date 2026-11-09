<?php

declare(strict_types=1);

namespace Cbox\Tax\Validators;

use Cbox\Geo\ValueObjects\CountryCode;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The one way a tax-ID validator reaches its registry: with a short connect and
 * request timeout, and through the {@see RegistryBackoff} when one is wired.
 *
 * Validators sit on a checkout's hot path, so a registry that hangs must cost
 * seconds, not the HTTP client's 30-second default. `null` back means "the
 * registry is unavailable" — the caller answers inconclusive, never throws.
 *
 * Expects the using class to declare `$http`, `$timeout`, `$connectTimeout` and
 * `$backoff` properties.
 */
trait CallsRegistry
{
    /**
     * @param  Closure(PendingRequest): Response  $send
     */
    private function callRegistry(string $source, CountryCode $country, Closure $send): ?Response
    {
        if ($this->backoff?->active($source, $country) === true) {
            return null;
        }

        try {
            $response = $send($this->http->connectTimeout($this->connectTimeout)->timeout($this->timeout));
        } catch (Throwable) {
            $this->backoff?->trip($source, $country);

            return null;
        }

        // The registry is down or shedding load: the same outcome as a timeout. Any
        // other status is an answer and is left to the validator to interpret.
        if ($response->serverError() || $response->status() === 429) {
            $this->backoff?->trip($source, $country);

            return null;
        }

        return $response;
    }
}
