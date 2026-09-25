#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * License gate. Fails (exit 1) if any installed dependency is not offered under
 * at least one allowed license. Dual-licensed packages (SPDX "OR", e.g. nette's
 * "BSD-3-Clause OR GPL-3.0-only") pass as long as ONE choice is allowed — which
 * is exactly the choice we exercise. Run: `composer license-check`.
 */
const ALLOWED = [
    'MIT', 'MIT-0', 'ISC', '0BSD', 'Unlicense', 'WTFPL', 'CC0-1.0',
    'BSD-2-Clause', 'BSD-3-Clause', 'BSD-3-Clause-Clear', 'BSD-4-Clause',
    'Apache-2.0', 'Apache2', 'BSL-1.0', 'Zlib', 'PHP-3.01',
];

/**
 * Packages permitted despite a missing/odd license field, each with its justification.
 *
 * @return array<string, string>
 */
function exceptions(): array
{
    return [
        // e.g. 'vendor/pkg' => 'public domain, confirmed upstream',
    ];
}

$lockPath = dirname(__DIR__).'/composer.lock';

if (! is_file($lockPath)) {
    fwrite(STDERR, "composer.lock not found; run `composer install` first.\n");
    exit(2);
}

$includeDev = in_array('--dev', arguments(), true);
$violations = [];
$checked = 0;

foreach (lockedPackages($lockPath, $includeDev) as $package) {
    $name = $package['name'];
    $checked++;

    if (array_key_exists($name, exceptions())) {
        continue;
    }

    $licenses = normalizeLicenses($package['license']);

    if ($licenses === []) {
        $violations[$name] = '(no license declared)';

        continue;
    }

    $allowed = array_filter($licenses, static fn (string $l): bool => in_array($l, ALLOWED, true));

    if ($allowed === []) {
        $violations[$name] = implode(' OR ', $licenses);
    }
}

/**
 * The command-line arguments, as strings.
 *
 * @return list<string>
 */
function arguments(): array
{
    $argv = $_SERVER['argv'] ?? [];

    return is_array($argv) ? array_values(array_filter($argv, is_string(...))) : [];
}

/**
 * Every locked package's name and declared licenses, read from the lock file's JSON
 * and narrowed here, at the boundary, so nothing below it handles an untyped value.
 *
 * @return list<array{name: string, license: list<string>}>
 */
function lockedPackages(string $lockPath, bool $includeDev): array
{
    $raw = file_get_contents($lockPath);
    $lock = $raw === false ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($lock)) {
        fwrite(STDERR, "composer.lock is not a JSON object.\n");
        exit(2);
    }

    $packages = [];

    foreach ($includeDev ? ['packages', 'packages-dev'] : ['packages'] as $section) {
        $list = $lock[$section] ?? [];

        foreach (is_array($list) ? $list : [] as $package) {
            if (! is_array($package)) {
                continue;
            }

            $name = $package['name'] ?? null;
            $license = $package['license'] ?? [];

            $packages[] = [
                'name' => is_string($name) ? $name : '?',
                'license' => array_values(array_filter(is_array($license) ? $license : [$license], is_string(...))),
            ];
        }
    }

    return $packages;
}

/**
 * Flatten a composer license field into individual SPDX identifiers, splitting
 * disjunctive/conjunctive expressions ("MIT OR GPL-2.0", "(MIT AND BSD)").
 *
 * @param  list<string>  $license
 * @return list<string>
 */
function normalizeLicenses(array $license): array
{
    $out = [];

    foreach ($license as $item) {
        foreach (preg_split('/\s+(?:OR|AND)\s+/i', trim($item)) ?: [] as $part) {
            $part = trim($part, " \t()");
            if ($part !== '') {
                $out[] = $part;
            }
        }
    }

    return array_values(array_unique($out));
}

$scope = $includeDev ? 'production + dev' : 'production';

if ($violations !== []) {
    fwrite(STDERR, "License check FAILED ({$scope}): disallowed or missing licenses\n\n");
    foreach ($violations as $name => $license) {
        fwrite(STDERR, sprintf("  %-45s %s\n", $name, $license));
    }
    fwrite(STDERR, "\nAllowed: ".implode(', ', ALLOWED)."\n");
    fwrite(STDERR, "If a flagged package is genuinely fine, add it to exceptions() with a reason.\n");
    exit(1);
}

echo "License check passed: all {$checked} {$scope} dependencies are permissively licensed.\n";
exit(0);
