<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

/**
 * The caller's input cannot be a request at all — a document with no lines, two lines
 * sharing an id, a supply whose place cannot be resolved from what was given.
 *
 * Fixable only by sending something different, which is what separates it from a
 * {@see Refusal}: there the request was valid and the answer is withheld.
 */
interface Malformed {}
