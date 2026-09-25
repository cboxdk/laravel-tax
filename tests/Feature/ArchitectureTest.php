<?php

declare(strict_types=1);

/*
 * OPEN BY DEFAULT. A host must be able to subclass, decorate or mock what this package
 * ships — a rate source with its own cache in front, a register adapter with one method
 * changed, a fake for a test. Forty-eight classes were sealed by habit, with nothing to
 * say which were meant to be. Sealing one now is a decision, made here, not a keyword.
 */
arch('ships no sealed classes')
    ->expect('Cbox\Tax')
    ->classes()
    ->not->toBeFinal();
