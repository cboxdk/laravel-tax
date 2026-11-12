<?php

declare(strict_types=1);

namespace Cbox\Tax\Tests\Fixtures;

use Cbox\Tax\Testing\DatasetConformance;

/**
 * Composition site so PHPStan analyses the dogfooded conformance trait.
 */
class UsesDatasetConformance
{
    use DatasetConformance;
}
