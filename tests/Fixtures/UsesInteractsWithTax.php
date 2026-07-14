<?php

declare(strict_types=1);

namespace Cbox\Tax\Tests\Fixtures;

use Cbox\Tax\Testing\InteractsWithTax;

/**
 * Composition site so PHPStan analyses the dogfooded testing trait.
 */
class UsesInteractsWithTax
{
    use InteractsWithTax;
}
