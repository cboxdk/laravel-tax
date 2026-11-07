<?php

declare(strict_types=1);

use Cbox\Tax\Testing\InteractsWithTax;
use PHPUnit\Framework\Attributes\After;

/*
 * The trait writes a one-off store per registerWith() call. Left behind, a suite
 * that runs a few thousand times fills the temp directory with millions of files;
 * so the trait removes what it wrote once the test is over.
 */

/** @return list<string> */
function traitStores(): array
{
    $found = glob(sys_get_temp_dir().'/cbox-tax-trait-'.getmypid().'-*');

    return $found === false ? [] : $found;
}

it('removes every store it wrote once the test is over', function (): void {
    $before = traitStores();

    $this->registerWith(['DK' => '25']);
    $this->registerWith(['DE' => '19']);

    expect(count(traitStores()))->toBe(count($before) + 2);

    $this->removeTaxRegisters();

    expect(traitStores())->toBe($before);
});

it('runs the clean-up as a PHPUnit after-test hook, so consumers need no wiring', function (): void {
    $method = new ReflectionMethod(InteractsWithTax::class, 'removeTaxRegisters');

    expect($method->getAttributes(After::class))->toHaveCount(1);
});
