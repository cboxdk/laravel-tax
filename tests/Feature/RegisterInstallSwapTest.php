<?php

declare(strict_types=1);

use Cbox\Tax\Exceptions\DatasetUnreadable;
use Cbox\Tax\Register\Compile\Compiler;
use Cbox\Tax\Register\Store\StoreLayout;

/*
 * The swap that makes a sync safe to run against a live store. It is documented as
 * atomic, and it was — for every version except the one that mattered.
 */

function swapStore(): string
{
    return test()->swapStore;
}

function installViaCompiler(string $partial, string $final): void
{
    $compiler = new ReflectionMethod(Compiler::class, 'install');
    $compiler->invoke(
        new ReflectionClass(Compiler::class)->newInstanceWithoutConstructor(),
        $partial,
        $final,
    );
}

beforeEach(function (): void {
    $this->swapStore = sys_get_temp_dir().'/cbox-tax-swap-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($this->swapStore, 0o775, true);
});

afterEach(function (): void {
    $remove = function (string $directory) use (&$remove): void {
        foreach (is_dir($directory) ? (array) scandir($directory) : [] as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $remove($path) : @unlink($path);
        }

        @rmdir($directory);
    };

    $remove($this->swapStore);
});

it('replaces a version that is already installed without uninstalling it first', function (): void {
    $final = swapStore().'/live';
    $partial = swapStore().'/live.partial';

    mkdir($final, 0o775, true);
    file_put_contents($final.'/manifest.json', '{"version":"old"}');
    mkdir($partial, 0o775, true);
    file_put_contents($partial.'/manifest.json', '{"version":"new"}');

    installViaCompiler($partial, $final);

    expect(json_decode((string) file_get_contents($final.'/manifest.json'), true)['version'])->toBe('new')
        // ...and the directory it moved aside is gone, not left to be counted later.
        ->and(glob(swapStore().'/*.superseded-*'))->toBe([]);
});

it('leaves the installed version exactly where it was when the swap fails', function (): void {
    // THE FAILURE THAT PROMPTED THIS. Deleting the destination and then renaming into
    // it reads as "replace" until you re-sync the version currently LIVE: the delete
    // uninstalls the running store, and anything between that and the rename — a full
    // disk, a killed deploy, a container evicted mid-step — leaves the pointer aimed
    // at a directory that no longer exists.
    $final = swapStore().'/live';

    mkdir($final, 0o775, true);
    file_put_contents($final.'/manifest.json', '{"version":"old"}');

    // A partial that is not there at all is the simplest way to make the second
    // rename fail after the first has already succeeded.
    expect(fn () => installViaCompiler(swapStore().'/absent.partial', $final))
        ->toThrow(DatasetUnreadable::class);

    expect(is_dir($final))->toBeTrue()
        ->and(json_decode((string) file_get_contents($final.'/manifest.json'), true)['version'])->toBe('old');
});

it('does not count a superseded leftover as an installed version', function (): void {
    // A crash between the two renames leaves one on disk. It carries a perfectly
    // valid manifest, so nothing about its contents excludes it — and counted as
    // installed it is one `prune --keep=2` away from deleting a real version to make
    // room for a corpse.
    $layout = new StoreLayout(swapStore());

    foreach (['2026.09.16-220', '2026.09.16-220.superseded-a1b2c3d4', '2026.09.15-202.partial'] as $version) {
        mkdir($layout->versions().'/'.$version, 0o775, true);
        file_put_contents($layout->versions().'/'.$version.'/manifest.json', '{}');
    }

    expect($layout->installed())->toBe(['2026.09.16-220']);
});
