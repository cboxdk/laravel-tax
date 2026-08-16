<?php

declare(strict_types=1);

use Brick\Money\Money;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\EuTaxData\EuTaxDataset;
use Cbox\Tax\RateSource\EuTaxDatasetRateSource;
use Cbox\Tax\Regime\EuVatRegime;
use Cbox\Tax\Territories\StaticEuTerritories;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;
use Cbox\Tax\ValueObjects\TaxAssessment;
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
