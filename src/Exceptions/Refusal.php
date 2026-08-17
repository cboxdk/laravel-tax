<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Cbox\Tax\Enums\RefusalReason;
use Throwable;

/**
 * The engine declines to answer. The request was fine; we will not guess.
 *
 * This is the house rule made catchable. A conditional taxability nobody supplied the
 * facts for, a place we have no rate for, a threshold in a currency we cannot compare
 * — each is a deliberate refusal rather than a failure, and the difference decides
 * what a caller does next.
 *
 * **A REFUSAL IS NOT A RETRY.** Over HTTP this is the 422 that tells a shop to fall
 * back to its own configured rate and flag the line for reconciliation. Sent as a 500
 * it would look transient, and a checkout would retry a question that will never have
 * a different answer.
 *
 * Extends Throwable so the marker cannot be put on something that is not an exception,
 * and so `@throws Refusal` is a claim a static analyser can check. Implemented
 * alongside whatever the exception already extends, so nothing that catches the
 * concrete classes today breaks.
 */
interface Refusal extends Throwable
{
    /**
     * Which refusal this is, in a form a consumer can switch on.
     *
     * Not optional, and that is the point. It was a marker for one release and the
     * message was all a caller had — an HTTP layer wanting to say what to do about a
     * 422 had nothing but prose to parse, and parsing it never once worked. A refusal
     * that cannot name itself is a refusal nobody can act on.
     */
    public function reason(): RefusalReason;
}
