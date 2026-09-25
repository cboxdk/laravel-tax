#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generates a CycloneDX 1.5 SBOM (JSON) from composer.lock — self-contained, no
 * plugins or network. Output is deterministic (components sorted, serial number
 * derived from content) so a committed SBOM only changes when dependencies do.
 *
 *   composer sbom              # production dependencies -> sbom.json
 *   php bin/generate-sbom.php --dev --output=sbom-dev.json
 */
$root = dirname(__DIR__);
$arguments = arguments();
$includeDev = in_array('--dev', $arguments, true);
$output = $root.'/sbom.json';

foreach ($arguments as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $output = substr($arg, strlen('--output='));
    }
}

$packages = lockedPackages($root.'/composer.lock', $includeDev);
usort($packages, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

$components = array_map(componentFor(...), $packages);
$self = packageName($root.'/composer.json');

$serial = 'urn:uuid:'.deterministicUuid(implode('|', array_map(static fn (array $component): string => $component['purl'], $components)));

$bom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'serialNumber' => $serial,
    'version' => 1,
    'metadata' => [
        'tools' => [[
            'vendor' => 'cboxdk',
            'name' => 'laravel-tax-sbom',
            'version' => '1.0.0',
        ]],
        'component' => [
            'type' => 'library',
            'bom-ref' => $self,
            'name' => $self,
            'purl' => 'pkg:composer/'.$self,
        ],
    ],
    'components' => $components,
];

file_put_contents(
    $output,
    json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);

printf("Wrote %s: %d components (%s).\n", $output, count($components), $includeDev ? 'production + dev' : 'production');

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
 * A JSON file decoded to an object, or the script stops: a lock file that is not one
 * cannot describe a bill of materials.
 *
 * @return array<array-key, mixed>
 */
function jsonObject(string $path): array
{
    $raw = is_file($path) ? file_get_contents($path) : false;
    $decoded = $raw === false ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        fwrite(STDERR, "{$path} is missing or not a JSON object.\n");
        exit(2);
    }

    return $decoded;
}

function packageName(string $composerJson): string
{
    $name = jsonObject($composerJson)['name'] ?? null;

    return is_string($name) ? $name : 'cboxdk/laravel-tax';
}

/**
 * Every locked package, narrowed at the JSON boundary to what the bill of materials
 * reads, so nothing below it handles an untyped value.
 *
 * @return list<array{name: string, version: string, description: ?string, license: list<string>, shasum: ?string}>
 */
function lockedPackages(string $lockPath, bool $includeDev): array
{
    $lock = jsonObject($lockPath);
    $packages = [];

    foreach ($includeDev ? ['packages', 'packages-dev'] : ['packages'] as $section) {
        $list = $lock[$section] ?? [];

        foreach (is_array($list) ? $list : [] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                continue;
            }

            $license = $package['license'] ?? [];
            $dist = $package['dist'] ?? null;
            $shasum = is_array($dist) ? ($dist['shasum'] ?? null) : null;

            $packages[] = [
                'name' => $package['name'],
                'version' => is_string($package['version'] ?? null) ? $package['version'] : '0.0.0',
                'description' => is_string($package['description'] ?? null) ? $package['description'] : null,
                'license' => array_values(array_filter(is_array($license) ? $license : [$license], is_string(...))),
                'shasum' => is_string($shasum) && $shasum !== '' ? $shasum : null,
            ];
        }
    }

    return $packages;
}

/**
 * @param  array{name: string, version: string, description: ?string, license: list<string>, shasum: ?string}  $package
 * @return array{type: string, bom-ref: string, group: string, name: string, version: string, purl: string, description?: string, licenses?: list<array<string, mixed>>, hashes?: list<array{alg: string, content: string}>}
 */
function componentFor(array $package): array
{
    $name = $package['name'];
    $version = $package['version'];
    $purl = 'pkg:composer/'.$name.'@'.$version;
    [$group, $short] = array_pad(explode('/', $name, 2), 2, $name);

    $component = [
        'type' => 'library',
        'bom-ref' => $purl,
        'group' => $group,
        'name' => $short,
        'version' => $version,
        'purl' => $purl,
    ];

    if ($package['description'] !== null) {
        $component['description'] = $package['description'];
    }

    $licenses = licenseEntries($package['license']);
    if ($licenses !== []) {
        $component['licenses'] = $licenses;
    }

    if ($package['shasum'] !== null) {
        $component['hashes'] = [['alg' => 'SHA-1', 'content' => $package['shasum']]];
    }

    return $component;
}

/**
 * @param  list<string>  $items
 * @return list<array<string, mixed>>
 */
function licenseEntries(array $items): array
{
    if ($items === []) {
        return [];
    }

    // A single declared license -> SPDX id; multiple -> an SPDX expression.
    if (count($items) === 1) {
        return [['license' => ['id' => $items[0]]]];
    }

    return [['expression' => '('.implode(' OR ', $items).')']];
}

function deterministicUuid(string $seed): string
{
    $hash = md5('cboxdk/laravel-tax:'.$seed);

    return sprintf(
        '%s-%s-4%s-%s-%s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        substr($hash, 13, 3),
        dechex((hexdec($hash[16]) & 0x3) | 0x8).substr($hash, 17, 3),
        substr($hash, 20, 12),
    );
}
