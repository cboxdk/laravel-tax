<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

/**
 * Something upstream is unavailable. The same request may well succeed later.
 *
 * A rate source that timed out, answered badly, or returned something unreadable.
 * Distinct from a {@see Refusal} precisely because retrying is the right response
 * here and the wrong one there.
 */
interface Transient {}
