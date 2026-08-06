#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Watches the authority pages behind the shipped rate overlay and reports when one
 * changes.
 *
 *   php bin/watch-rates.php [--update] [--only=GB,NO]
 *
 * The 50-odd national rates in resources/rates.json have no live feed, because none
 * exists — the OECD's statistical API carries no VAT/GST rate dataflow, and the
 * authorities publish their rates as prose. The risk that creates is not answering
 * wrongly today; it is that a rate moves and nobody notices for months.
 *
 * So this does NOT try to parse a rate out of a page. It hashes the page's readable
 * text and compares it to a committed lockfile: a change means "go and look", and a
 * human decides whether the rate actually moved and updates the overlay. Parsing a
 * percentage out of prose would be a guess that fails silently, which is the exact
 * failure this is meant to catch.
 *
 * Exit codes: 0 unchanged, 1 something changed (or a page could not be read), 2 the
 * lockfile needed writing. `--update` records the current state as the baseline.
 */
$root = dirname(__DIR__);
$overlay = $root.'/resources/rates.json';
$lockfile = $root.'/resources/rates-watch.json';

$options = getopt('', ['update', 'only::']);
$update = array_key_exists('update', $options);
$only = is_string($options['only'] ?? null) && $options['only'] !== ''
    ? array_map(strtoupper(...), explode(',', $options['only']))
    : null;

$rates = json_decode((string) file_get_contents($overlay), true);

if (! is_array($rates) || ! is_array($rates['rates'] ?? null)) {
    fwrite(STDERR, "Could not read the rate overlay.\n");

    exit(1);
}

$lock = is_readable($lockfile) ? json_decode((string) file_get_contents($lockfile), true) : null;
$known = is_array($lock) && is_array($lock['pages'] ?? null) ? $lock['pages'] : [];

/**
 * The readable text of a page: scripts, styles, comments and markup removed, runs of
 * whitespace collapsed, and long bare integers dropped.
 *
 * That last rule is not cosmetic. The UAE's page carries a visitor counter, so two
 * fetches a second apart differ by one digit and every run would report a change —
 * an alarm that cries wolf is worse than none, because it trains you to ignore it.
 * Six digits or more is safely past any tax rate (the longest we ship is "14.975"),
 * so counters, build ids and epoch timestamps go while rates stay.
 */
$readable = static function (string $body): string {
    $text = preg_replace('#<script.*?</script>|<style.*?</style>|<!--.*?-->#si', '', $body) ?? $body;
    $text = preg_replace('#<[^>]+>#', ' ', $text) ?? $text;
    $text = preg_replace('/\b\d{6,}\b/', '', $text) ?? $text;

    return trim((string) preg_replace('/\s+/u', ' ', $text));
};

$context = stream_context_create(['http' => [
    'timeout' => 45,
    'follow_location' => 1,
    'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36\r\n",
]]);

$pages = [];
$changed = [];
$unreadable = [];

foreach ($rates['rates'] as $code => $entry) {
    $url = is_array($entry) && is_string($entry['watch'] ?? null) ? $entry['watch'] : null;

    if ($url === null || ($only !== null && ! in_array($code, $only, true))) {
        continue;
    }

    $body = @file_get_contents($url, false, $context);

    if ($body === false || $body === '') {
        $unreadable[] = sprintf('%s — %s', $code, $url);

        // Keep the previous baseline: an unreachable page is not a changed one.
        if (isset($known[$code])) {
            $pages[$code] = $known[$code];
        }

        continue;
    }

    $hash = hash('sha256', $readable($body));
    $previous = is_array($known[$code] ?? null) ? ($known[$code]['sha256'] ?? null) : null;

    if (is_string($previous) && $previous !== $hash) {
        // Confirm before reporting. Some pages vary between fetches in ways no
        // normalisation catches — Malaysia's serves occasional variants — and a
        // single differing hash is not evidence a rate moved. Only the pages that
        // look changed are fetched twice, so the cost is a request or two a run.
        sleep(2);
        $again = @file_get_contents($url, false, $context);
        $confirmed = is_string($again) && $again !== '' && hash('sha256', $readable($again)) === $hash;

        if ($confirmed) {
            $rate = $entry['windows'][count($entry['windows']) - 1]['rate'] ?? '?';
            $changed[] = sprintf('%s — %s (we ship %s%%)', $code, $url, $rate);
        } else {
            // Unconfirmed: keep the old baseline so the next run compares against
            // the same thing rather than silently adopting a transient variant.
            $pages[$code] = $known[$code];

            continue;
        }
    }

    $pages[$code] = [
        'url' => $url,
        'sha256' => $hash,
        'checkedAt' => gmdate('Y-m-d'),
        'changedAt' => is_string($previous) && $previous !== $hash
            ? gmdate('Y-m-d')
            : (is_array($known[$code] ?? null) ? ($known[$code]['changedAt'] ?? null) : null),
    ];
}

ksort($pages);

printf("watched %d page(s): %d changed, %d unreadable\n", count($pages), count($changed), count($unreadable));

foreach ($changed as $line) {
    printf("  CHANGED  %s\n", $line);
}

foreach ($unreadable as $line) {
    printf("  UNREAD   %s\n", $line);
}

if ($update || $known === []) {
    file_put_contents($lockfile, json_encode([
        'note' => 'Baseline hashes of the authority pages behind resources/rates.json. '
            .'A differing hash means the page moved, not that the rate did — it is a '
            .'prompt to verify, never an input to a calculation.',
        'pages' => $pages,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

    printf("baseline written to %s\n", basename($lockfile));

    exit($known === [] ? 2 : 0);
}

exit($changed === [] && $unreadable === [] ? 0 : 1);
