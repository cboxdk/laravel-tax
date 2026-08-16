<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

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
 * Marker only, and implemented alongside whatever the exception already extends, so
 * nothing that catches the concrete classes today breaks.
 */
interface Refusal {}
