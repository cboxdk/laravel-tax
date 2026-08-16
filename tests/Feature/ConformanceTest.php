<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\EuTaxData\EuTaxDataset;
use Cbox\Tax\RateSource\EuTaxDatasetRateSource;
use Cbox\Tax\Regime\EuVatRegime;
use Cbox\Tax\Territories\StaticEuTerritories;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\SupplyLine;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxOrder;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

/**
 * Runs the published conformance corpus against the engine.
 *
 * The corpus in `conformance/` is plain JSON with no PHP in it, on purpose: it
 * describes what a determination should ANSWER, not how this engine is built. It is
 * the drift guard between the library, the HTTP API and any embedded integration —
 * three consumers of one engine that otherwise drift apart quietly, each passing its
 * own tests while disagreeing with the others.
 *
 * It is also meant to be published. A category where no vendor discloses how their
 * answers are reached can be argued with, and the cheapest way to argue is to hand
 * over the cases we hold ourselves to.
 *
 * Vectors pin the committed dataset fixture rather than the live mirror. A rate that
 * changes in the world must not silently change what a vector asserts — that would
 * make the corpus a mirror of today's data instead of a description of behaviour.
 */

/**
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function conformanceVectors(): array
{
    $path = dirname(__DIR__, 2).'/conformance/vectors/eu-vat.json';
    $corpus = json_decode((string) file_get_contents($path), true);

    if (! is_array($corpus) || ! is_array($corpus['vectors'] ?? null)) {
        throw new RuntimeException("No vectors in {$path}.");
    }

    $out = [];

    foreach ($corpus['vectors'] as $vector) {
        if (! is_array($vector) || ! is_string($vector['id'] ?? null)) {
            throw new RuntimeException('A vector is missing its id.');
        }

        $out[] = [$vector['id'], $vector];
    }

    return $out;
}

function conformanceRegime(): EuVatRegime
{
    return new EuVatRegime(app(JurisdictionRepository::class), new StaticEuTerritories);
}

function conformanceRates(): EuTaxDatasetRateSource
{
    return new EuTaxDatasetRateSource(new EuTaxDataset(
        app(Factory::class),
        app(Cache::class),
        dirname(__DIR__).'/Fixtures/eu-tax-dataset',
    ));
}

/**
 * @param  array<string, mixed>  $q
 */
function conformanceQuery(array $q): TaxQuery
{
    $country = is_string($q['place'] ?? null) ? $q['place'] : throw new RuntimeException('place is required.');
    $sellerCountry = is_string($q['sellerCountry'] ?? null) ? $q['sellerCountry'] : $country;

    $registrations = [];

    foreach ((array) ($q['sellerRegistrations'] ?? []) as $code) {
        if (is_string($code)) {
            $registrations[] = new SellerRegistration(new CountryCode($code));
        }
    }

    $place = app(JurisdictionRepository::class)->find(new CountryCode($country))
        ?? throw new RuntimeException($country.' is not resolvable.');

    return new TaxQuery(
        amount: Money::of((string) $q['amount'], (string) $q['currency']),
        pricing: ($q['pricing'] ?? 'exclusive') === 'inclusive' ? Pricing::Inclusive : Pricing::Exclusive,
        place: $place,
        customer: ($q['customer'] ?? 'consumer') === 'business' ? CustomerType::Business : CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode($sellerCountry), $registrations),
        category: TaxClass::from((string) ($q['class'] ?? 'general_goods')),
        customerTaxIdValidated: (bool) ($q['customerTaxIdValidated'] ?? false),
        suppliedAt: is_string($q['suppliedAt'] ?? null) ? new DateTimeImmutable($q['suppliedAt']) : null,
        postalCode: is_string($q['postalCode'] ?? null) ? $q['postalCode'] : null,
    );
}

/**
 * @param  array<string, mixed>  $expect
 * @return list<string> the failures, so one vector reports all of them at once
 */
function conformanceFailures(TaxAssessment $got, array $expect): array
{
    $bad = [];
    $money = static fn (Money $m): string => (string) $m->getAmount();

    if (is_string($expect['treatment'] ?? null) && $got->treatment->value !== $expect['treatment']) {
        $bad[] = "treatment: expected {$expect['treatment']}, got {$got->treatment->value}";
    }

    if (is_string($expect['treatmentNot'] ?? null) && $got->treatment->value === $expect['treatmentNot']) {
        $bad[] = "treatment: must not be {$expect['treatmentNot']}";
    }

    foreach (['net', 'tax', 'gross'] as $field) {
        if (! is_string($expect[$field] ?? null)) {
            continue;
        }

        $actual = $money($got->{$field});

        if ($actual !== $expect[$field]) {
            $bad[] = "{$field}: expected {$expect[$field]}, got {$actual}";
        }
    }

    if (is_string($expect['ratePercentage'] ?? null)) {
        $actual = (string) $got->rate?->percentage;

        if ($actual !== $expect['ratePercentage']) {
            $bad[] = "rate: expected {$expect['ratePercentage']}%, got {$actual}%";
        }
    }

    if (is_string($expect['ratePercentageBelow'] ?? null)) {
        $ceiling = (float) $expect['ratePercentageBelow'];
        $actual = (float) (string) $got->rate?->percentage;

        if ($actual >= $ceiling) {
            $bad[] = "rate: expected below {$ceiling}%, got {$actual}% — the reduced band was not reached";
        }
    }

    if (($expect['taxGreaterThanZero'] ?? false) === true && $got->tax->isZero()) {
        $bad[] = 'tax: expected a charge, got zero';
    }

    if (($expect['hasInvoiceMention'] ?? false) === true && $got->mentions === []) {
        $bad[] = 'mentions: expected a legal statement on the invoice, got none';
    }

    // A zero-decimal currency must not acquire minor units on the way through.
    if (($expect['taxHasNoFractionalUnits'] ?? false) === true && str_contains($money($got->tax), '.')) {
        $bad[] = 'tax: expected whole units for a zero-decimal currency, got '.$money($got->tax);
    }

    return $bad;
}

it('answers the published conformance corpus', function (string $id, array $vector) {
    $expect = is_array($vector['expect'] ?? null) ? $vector['expect'] : [];
    $query = is_array($vector['query'] ?? null) ? $vector['query'] : [];

    $assessment = conformanceRegime()->assess(conformanceQuery($query), conformanceRates());

    $failures = conformanceFailures($assessment, $expect);

    // Reported as one message rather than a chain of expectations, so a broken vector
    // shows everything that is wrong with it in a single run instead of one field per
    // fix-and-rerun cycle.
    expect($failures)->toBe([], sprintf(
        "Vector %s failed.\n  Pins: %s\n  %s",
        $id,
        is_string($vector['pins'] ?? null) ? $vector['pins'] : '(undocumented)',
        implode("\n  ", $failures),
    ));
})->with(conformanceVectors());

/**
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function conformanceOrders(): array
{
    $path = dirname(__DIR__, 2).'/conformance/vectors/eu-vat.json';
    $corpus = json_decode((string) file_get_contents($path), true);
    $orders = is_array($corpus) && is_array($corpus['orders'] ?? null) ? $corpus['orders'] : [];

    $out = [];

    foreach ($orders as $order) {
        if (is_array($order) && is_string($order['id'] ?? null)) {
            $out[] = [$order['id'], $order];
        }
    }

    return $out;
}

// The document shape, not the line shape. Some things are only wrong at order level —
// a per-delivery fee charged once per line, a postcode that never reached the lines —
// and a corpus of single supplies cannot see any of them.
it('answers the published order-shaped vectors', function (string $id, array $vector) {
    $spec = is_array($vector['order'] ?? null) ? $vector['order'] : [];
    $expect = is_array($vector['expect'] ?? null) ? $vector['expect'] : [];

    $lines = [];

    foreach ((array) ($spec['lines'] ?? []) as $line) {
        $lines[] = new SupplyLine(
            id: (string) $line['id'],
            amount: Money::of((string) $line['amount'], (string) $spec['currency']),
            category: TaxClass::from((string) ($line['class'] ?? 'general_goods')),
        );
    }

    $registrations = [];

    foreach ((array) ($spec['sellerRegistrations'] ?? []) as $code) {
        $registrations[] = new SellerRegistration(new CountryCode((string) $code));
    }

    $document = app(OrderTaxCalculator::class)->assessOrder(new TaxOrder(
        place: app(JurisdictionRepository::class)->find(new CountryCode((string) $spec['place']))
            ?? throw new RuntimeException('Unresolvable place.'),
        customer: ($spec['customer'] ?? 'consumer') === 'business' ? CustomerType::Business : CustomerType::Consumer,
        seller: new SellerRegistrations(new CountryCode((string) ($spec['sellerCountry'] ?? $spec['place'])), $registrations),
        pricing: ($spec['pricing'] ?? 'exclusive') === 'inclusive' ? Pricing::Inclusive : Pricing::Exclusive,
        lines: $lines,
        suppliedAt: is_string($spec['suppliedAt'] ?? null) ? new DateTimeImmutable($spec['suppliedAt']) : null,
    ));

    $failures = [];

    if (is_int($expect['lineCount'] ?? null) && count($document->lines) !== $expect['lineCount']) {
        $failures[] = sprintf('lineCount: expected %d, got %d', $expect['lineCount'], count($document->lines));
    }

    // A credit and its replacement charge on one date must resolve one rate. Two
    // rates on one document leave a residue that reconciles to nothing.
    if (($expect['sameRateAcrossLines'] ?? false) === true) {
        $rates = array_unique(array_map(
            static fn ($line): string => (string) $line->assessment->rate?->percentage,
            $document->lines,
        ));

        if (count($rates) > 1) {
            $failures[] = 'rate: one document resolved several rates — '.implode(', ', $rates);
        }
    }

    foreach (['netTotal' => 'net', 'taxTotal' => 'tax', 'grossTotal' => 'gross'] as $key => $field) {
        if (! is_string($expect[$key] ?? null)) {
            continue;
        }

        $total = null;

        foreach ($document->lines as $line) {
            $total = $total === null ? $line->assessment->{$field} : $total->plus($line->assessment->{$field});
        }

        $actual = $total === null ? '(no lines)' : (string) $total->getAmount();

        if ($actual !== $expect[$key]) {
            $failures[] = "{$key}: expected {$expect[$key]}, got {$actual}";
        }
    }

    expect($failures)->toBe([], sprintf(
        "Order vector %s failed.\n  Pins: %s\n  %s",
        $id,
        is_string($vector['pins'] ?? null) ? $vector['pins'] : '(undocumented)',
        implode("\n  ", $failures),
    ));
})->with(conformanceOrders());

// What the engine cannot express yet is recorded IN the corpus rather than left out
// of it. A gap nobody wrote down is indistinguishable from a gap nobody found, and
// this one — shipping following the goods under Art. 78(b) — is the most common line
// in e-commerce. Each entry must carry both why it is absent and what decision is
// open, so it cannot decay into a shrug.
it('states what it deliberately does not model', function () {
    $path = dirname(__DIR__, 2).'/conformance/vectors/eu-vat.json';
    $corpus = json_decode((string) file_get_contents($path), true);
    $gaps = is_array($corpus) && is_array($corpus['notModelled'] ?? null) ? $corpus['notModelled'] : [];

    $thin = [];

    foreach ($gaps as $gap) {
        $why = is_array($gap) ? ($gap['why'] ?? null) : null;
        $decision = is_array($gap) ? ($gap['openDecision'] ?? null) : null;

        if (! is_string($why) || strlen($why) < 60 || ! is_string($decision) || strlen($decision) < 40) {
            $thin[] = is_array($gap) && is_string($gap['id'] ?? null) ? $gap['id'] : '(unnamed)';
        }
    }

    expect($thin)->toBe([]);
});

// A misspelled expectation key is READ BY NOBODY and the vector passes anyway — the
// same silence that hid three broken guards elsewhere this week. Every key a vector
// asserts must be one the runner actually understands.
it('understands every expectation a vector states', function () {
    $known = [
        'treatment', 'treatmentNot', 'net', 'tax', 'gross', 'ratePercentage',
        'ratePercentageBelow', 'taxGreaterThanZero', 'hasInvoiceMention',
        'taxHasNoFractionalUnits',
        // Order-shaped
        'lineCount', 'sameRateAcrossLines', 'netTotal', 'taxTotal', 'grossTotal',
        // Documentary: carried for the reader, deliberately not asserted because the
        // fixture pins one dated window and the assertion would restate the fixture.
        'rateResolvedForDate', 'why',
    ];

    $unknown = [];

    foreach ([...conformanceVectors(), ...conformanceOrders()] as [$id, $vector]) {
        foreach (array_keys((array) ($vector['expect'] ?? [])) as $key) {
            if (! in_array($key, $known, true)) {
                $unknown[] = "{$id}: {$key}";
            }
        }
    }

    expect($unknown)->toBe([]);
});

// Every vector must say what it pins. A corpus meant to be handed to someone else is
// worth nothing as a list of opaque assertions — the sentence is the deliverable as
// much as the numbers are.
it('documents what every vector pins', function () {
    $undocumented = [];

    foreach (conformanceVectors() as [$id, $vector]) {
        $pins = $vector['pins'] ?? null;

        if (! is_string($pins) || strlen($pins) < 30) {
            $undocumented[] = $id;
        }
    }

    expect($undocumented)->toBe([]);
});
