#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Runs the conformance corpus against an HTTP implementation.
 *
 *   php conformance/run-http.php --base=https://api.example.com --key=sk_live_…
 *   php conformance/run-http.php --base=http://localhost:8000 --corpus=eu-vat
 *
 * The same vectors the library is held to, sent over the wire. That is the whole
 * point of the corpus: one engine reached three ways — as a package, as this API, as
 * an embedded integration — drifts apart quietly, each passing its own tests while
 * disagreeing with the others, and nobody finding out until a customer does.
 *
 * WRITTEN BEFORE THE API IT TESTS, deliberately. A corpus retrofitted to a service
 * documents whatever that service already does; a corpus written first is a contract
 * the service has to satisfy. Everything below — the request shape, the three
 * outcomes, the field names — is the specification, executable.
 *
 * No framework and no dependencies: a consumer checking whether we answer what we
 * claim should not have to install our stack to find out. Plain curl and json.
 *
 * Exit codes: 0 every vector passed · 1 at least one did not · 2 the run itself
 * could not happen. The last matters — a suite that cannot reach the service must
 * not look like a suite that found nothing wrong.
 */

$options = getopt('', ['base:', 'key::', 'corpus::', 'quiet']);
$base = is_string($options['base'] ?? null) ? rtrim($options['base'], '/') : null;

if ($base === null) {
    fwrite(STDERR, "Usage: php conformance/run-http.php --base=URL [--key=APIKEY] [--corpus=NAME]\n");

    exit(2);
}

$only = is_string($options['corpus'] ?? null) ? $options['corpus'] : null;
$quiet = array_key_exists('quiet', $options);
$key = is_string($options['key'] ?? null) ? $options['key'] : null;

/**
 * The corpora, or the one asked for.
 *
 * @return list<array<string, mixed>>
 */
function corpora(?string $only): array
{
    $found = [];

    foreach (glob(__DIR__.'/vectors/*.json') ?: [] as $path) {
        $corpus = json_decode((string) file_get_contents($path), true);

        if (! is_array($corpus)) {
            fwrite(STDERR, "Unreadable corpus at {$path}.\n");

            exit(2);
        }

        if ($only !== null && ($corpus['corpus'] ?? null) !== $only) {
            continue;
        }

        $found[] = $corpus;
    }

    if ($found === []) {
        // An empty run passing is the failure mode this whole file exists to prevent.
        fwrite(STDERR, "No corpora matched. Refusing to report success on nothing.\n");

        exit(2);
    }

    return $found;
}

/**
 * @param  array<string, mixed>  $body
 * @return array{status: int, body: array<string, mixed>|null, error: ?string}
 */
function post(string $url, array $body, ?string $key): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];

    if ($key !== null) {
        $headers[] = 'Authorization: Bearer '.$key;
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle) ?: null;
    curl_close($handle);

    if ($raw === false) {
        return ['status' => 0, 'body' => null, 'error' => $error ?? 'the request failed'];
    }

    $decoded = json_decode((string) $raw, true);

    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : null,
        'error' => is_array($decoded) ? null : 'the response was not JSON: '.substr((string) $raw, 0, 120),
    ];
}

/**
 * A vector's query as the API takes it. This mapping IS the request contract.
 *
 * @param  array<string, mixed>  $q
 * @return array<string, mixed>
 */
function requestFor(array $q, string $regime): array
{
    $line = ['id' => '1', 'amount' => $q['amount'] ?? null];

    foreach (['class' => 'class', 'commodityCode' => 'commodityCode', 'itemCode' => 'itemCode'] as $from => $to) {
        if (isset($q[$from])) {
            $line[$to] = $q[$from];
        }
    }

    $body = [
        'currency' => $q['currency'] ?? null,
        'pricing' => $q['pricing'] ?? 'exclusive',
        'destination' => ['place' => $q['place'] ?? null],
        'customer' => [
            'type' => $q['customer'] ?? 'consumer',
            'taxIdValidated' => (bool) ($q['customerTaxIdValidated'] ?? false),
        ],
        'seller' => [
            'country' => $q['sellerCountry'] ?? $q['place'] ?? null,
            'registrations' => $q['sellerRegistrations'] ?? [],
        ],
        'regime' => $regime,
        'lines' => [$line],
    ];

    if (isset($q['postalCode'])) {
        $body['destination']['postalCode'] = $q['postalCode'];
    }

    if (isset($q['suppliedAt'])) {
        $body['suppliedAt'] = $q['suppliedAt'];
    }

    if (($q['marketplaceFacilitated'] ?? false) === true) {
        $body['marketplaceFacilitated'] = true;
    }

    return $body;
}

/**
 * What the response has to satisfy. Kept in step with the library runner in
 * `tests/Feature/ConformanceTest.php` — the two disagreeing is precisely the drift
 * this corpus exists to surface, so a key understood by one must be understood by
 * both.
 *
 * @param  array{status: int, body: array<string, mixed>|null, error: ?string}  $response
 * @param  array<string, mixed>  $expect
 * @return list<string>
 */
function failures(array $response, array $expect): array
{
    if ($response['error'] !== null) {
        return [$response['error']];
    }

    // A refusal is a 422 carrying which limit stopped it. Any other 4xx or a 5xx is
    // the service being wrong about what kind of thing just happened.
    if ($response['status'] === 422) {
        $limit = $response['body']['limit'] ?? null;

        return $limit === null
            ? ['refused with 422 but named no limit, so a caller cannot tell what to do about it']
            : [];
    }

    if ($response['status'] !== 200) {
        return [sprintf('HTTP %d — expected 200, or 422 with a limit', $response['status'])];
    }

    $line = $response['body']['lines'][0] ?? null;

    if (! is_array($line)) {
        return ['200 with no lines[0] to read'];
    }

    $bad = [];

    foreach (['treatment' => 'treatment', 'net' => 'net', 'tax' => 'tax', 'gross' => 'gross'] as $key => $field) {
        if (isset($expect[$key]) && ($line[$field] ?? null) !== $expect[$key]) {
            $bad[] = sprintf('%s: expected %s, got %s', $field, $expect[$key], var_export($line[$field] ?? null, true));
        }
    }

    if (isset($expect['treatmentNot']) && ($line['treatment'] ?? null) === $expect['treatmentNot']) {
        $bad[] = 'treatment: must not be '.$expect['treatmentNot'];
    }

    if (isset($expect['ratePercentage']) && (string) ($line['ratePercentage'] ?? '') !== $expect['ratePercentage']) {
        $bad[] = sprintf('ratePercentage: expected %s, got %s', $expect['ratePercentage'], var_export($line['ratePercentage'] ?? null, true));
    }

    if (($expect['taxGreaterThanZero'] ?? false) === true && (float) ($line['tax'] ?? 0) <= 0.0) {
        $bad[] = 'tax: expected a charge, got '.var_export($line['tax'] ?? null, true);
    }

    // The reason is not decoration. It is the thing no competitor returns, and a
    // response without one has dropped the part that makes the number defensible.
    if (! is_string($line['reason'] ?? null) || $line['reason'] === '') {
        $bad[] = 'reason: missing — the number is not defensible without it';
    }

    return $bad;
}

$failed = 0;
$ran = 0;

foreach (corpora($only) as $corpus) {
    $regime = is_string($corpus['regime'] ?? null) ? $corpus['regime'] : 'eu-vat';

    foreach ((array) ($corpus['vectors'] ?? []) as $vector) {
        if (! is_array($vector) || ! is_string($vector['id'] ?? null)) {
            continue;
        }

        $ran++;
        $response = post($base.'/v1/assessments', requestFor((array) ($vector['query'] ?? []), $regime), $key);
        $bad = failures($response, (array) ($vector['expect'] ?? []));

        if ($bad === []) {
            if (! $quiet) {
                printf("  ok    %s/%s\n", $regime, $vector['id']);
            }

            continue;
        }

        $failed++;
        printf("  FAIL  %s/%s\n", $regime, $vector['id']);
        printf("        pins: %s\n", is_string($vector['pins'] ?? null) ? $vector['pins'] : '(undocumented)');

        foreach ($bad as $line) {
            printf("        %s\n", $line);
        }
    }
}

printf("\n%d vector(s), %d failed.\n", $ran, $failed);

exit($failed === 0 ? 0 : 1);
