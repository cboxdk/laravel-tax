<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\EuTaxData\EuTaxDataset;
use Cbox\Tax\RateSource\EuTaxDatasetRateSource;
use Cbox\Tax\ValueObjects\TaxRate;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

// The fixture is a slice of the genuinely published cboxdk/eu-tax-dataset — five
// member states, 56 dated windows, with the manifest's hashes recomputed for the
// slice. Nothing here is constructed: the rates, the bands, the determinations and
// the dated boundaries are what the pipeline actually published.

function euSource(?string $location = null): EuTaxDatasetRateSource
{
    return new EuTaxDatasetRateSource(new EuTaxDataset(
        app(Factory::class),
        app(Cache::class),
        $location ?? dirname(__DIR__).'/Fixtures/eu-tax-dataset',
    ));
}

function euPlace(string $country): Jurisdiction
{
    // Resolved through the repository rather than constructed, so the test exercises
    // the same jurisdiction shape the engine hands the source in production.
    return app(JurisdictionRepository::class)->find(new CountryCode($country))
        ?? throw new RuntimeException($country.' is not resolvable.');
}

function euRate(string $country, TaxClass $class, ?string $on = null): ?TaxRate
{
    return euSource()->rateFor(euPlace($country), $class, $on === null ? null : new DateTimeImmutable($on));
}

it('answers with the standard rate for a class the EU reduces nowhere', function () {
    // Most supplies land here and it is the correct answer, not a fallback: the
    // union reduces nothing for electronics or furniture.
    $rate = euRate('DK', TaxClass::Electronics);

    expect((string) $rate?->percentage)->toBe('25')
        ->and($rate?->kind)->toBe(RateKind::Standard)
        ->and($rate?->confidence)->toBe(Confidence::Authoritative);
});

it('finds a reduced band through the published class map', function () {
    // groceries → FOODSTUFFS. The map ships with the rates precisely so a consumer
    // does not have to know that.
    $rate = euRate('FR', TaxClass::Groceries);

    expect((string) $rate?->percentage)->toBe('5.5')
        ->and($rate?->kind)->toBe(RateKind::Reduced)
        ->and($rate?->source)->toContain('FOODSTUFFS');
});

it('tries the headings in order, because France has no BOOKS heading', function () {
    // Printed books sit under LOAN_LIBRARIES in France — member states split Annex
    // III point 6 at different granularities. A consumer trying only BOOKS finds
    // nothing and charges 20% on a book.
    $rate = euRate('FR', TaxClass::Book);

    expect((string) $rate?->percentage)->toBe('5.5')
        ->and($rate?->source)->toContain('LOAN_LIBRARIES');
});

// ---- The point of the history --------------------------------------------------

it('prices a supply with the rate that applied on its date', function () {
    // Estonia: 20% until 2024, 22% until mid-2025, 24% since. This is the whole
    // reason the dataset carries a dated series — an invoice being corrected two
    // years later must reprice at the rate that applied then, not today's.
    expect((string) euRate('EE', TaxClass::Electronics, '2023-06-01')?->percentage)->toBe('20')
        ->and((string) euRate('EE', TaxClass::Electronics, '2024-06-01')?->percentage)->toBe('22')
        ->and((string) euRate('EE', TaxClass::Electronics, '2025-06-01')?->percentage)->toBe('22')
        ->and((string) euRate('EE', TaxClass::Electronics, '2025-08-01')?->percentage)->toBe('24')
        ->and((string) euRate('EE', TaxClass::Electronics, '2026-08-01')?->percentage)->toBe('24');
});

it('refuses a date before the records begin rather than inventing one', function () {
    // The archive starts 2016-01-01. Estonia charged 20% in 2015 too, but nobody
    // observed it here — and answering anyway would hand back a rate this dataset
    // never asserted, on an invoice, with nothing marking it as inferred.
    expect(euRate('EE', TaxClass::Electronics, '2014-06-01'))->toBeNull();
});

it('denies a country the dataset does not carry', function () {
    // Not 0%. The engine refuses rather than assuming no VAT.
    expect(euRate('NO', TaxClass::Electronics))->toBeNull();
});

// ---- The undecided case, which is the careful one ------------------------------

it('drops confidence where the source rates a heading several ways', function () {
    // Hungary's groceries are 5% for meat and fish and 18% for dairy desserts and
    // cereals; no single answer exists at this granularity, so the dataset publishes
    // the ambiguity rather than picking. The standard rate is the safe fallback —
    // over-charging is recoverable — but a caller billing on it should be able to
    // see that a better answer exists.
    $rate = euRate('HU', TaxClass::Groceries);

    expect((string) $rate?->percentage)->toBe('27')
        ->and($rate?->kind)->toBe(RateKind::Standard)
        ->and($rate?->confidence)->toBe(Confidence::Derived)
        ->and($rate?->source)->toContain('undecided');
});

it('lets an undecided heading stop the search rather than answering a different question', function () {
    // If the first heading is ambiguous, falling through to the next would quietly
    // price the supply under a heading nobody asked about. Asserted against the real
    // Hungarian data: FOODSTUFFS is undecided and `groceries` maps to it alone, so
    // the answer must be the standard rate rather than a miss.
    expect(euRate('HU', TaxClass::Groceries)?->kind)->toBe(RateKind::Standard);
});

// ---- Integrity -----------------------------------------------------------------

it('names how a determined band was arrived at', function () {
    // A `determination` band means somebody chose between rates TEDB carried at
    // once. Saying so lets an operator check the call rather than take it.
    $rate = euRate('FR', TaxClass::PrescriptionMedicine);

    expect($rate?->source)->toContain('determined');
});

it('trusts a local path without a manifest, and a remote one not at all', function () {
    // Reading your own disk is a deliberate act. A URL that answers without a
    // manifest is a fetch that went somewhere unexpected, and the published location
    // is a branch head on a third-party host — one bad push would otherwise reach
    // every deployment within one cache TTL with nobody having released anything.
    expect(euRate('DK', TaxClass::Electronics))->not->toBeNull();

    $remote = euSource('https://example.invalid/eu-tax-dataset');

    expect($remote->rateFor(euPlace('DK'), TaxClass::Electronics))->toBeNull();
});

// ---- Corrupt data, and the blast radius of getting it wrong --------------------

function euCorrupt(string $mutate): EuTaxDatasetRateSource
{
    // A copy of the real fixture with one value damaged, and the manifest left
    // alone — a local path is trusted without one, which is exactly the case where
    // corrupt bytes can reach the source.
    $dir = sys_get_temp_dir().'/eu-corrupt-'.bin2hex(random_bytes(6));

    mkdir($dir.'/by-section', 0o755, true);

    $rates = (string) file_get_contents(dirname(__DIR__).'/Fixtures/eu-tax-dataset/by-section/rates.json');
    file_put_contents($dir.'/by-section/rates.json', $mutate === '' ? $rates : $mutate);
    copy(
        dirname(__DIR__).'/Fixtures/eu-tax-dataset/by-section/class-map.json',
        $dir.'/by-section/class-map.json',
    );

    return new EuTaxDatasetRateSource(new EuTaxDataset(app(Factory::class), app(Cache::class), $dir));
}

/** @param  callable(array<string, mixed>): array<string, mixed>  $edit */
function euWithEditedFr(callable $edit): EuTaxDatasetRateSource
{
    $rates = json_decode(
        (string) file_get_contents(dirname(__DIR__).'/Fixtures/eu-tax-dataset/by-section/rates.json'),
        true,
    );

    foreach ($rates['states']['FR'] as $i => $window) {
        if (($window['effectiveTo'] ?? null) === null) {
            $rates['states']['FR'][$i] = $edit($window);
        }
    }

    return euCorrupt((string) json_encode($rates));
}

it('charges the standard rate when a band is not a rate, rather than taking the engine down', function () {
    // The publisher refuses to emit a rate outside 0–100, so reaching here means
    // verification passed and something else went wrong. Throwing would fail every
    // assessment for every country over one bad heading in one — so the supply is
    // priced at the standard rate, and the confidence says why.
    $source = euWithEditedFr(function (array $window): array {
        $window['bands']['FOODSTUFFS']['rate'] = 'not-a-rate';

        return $window;
    });

    $rate = $source->rateFor(euPlace('FR'), TaxClass::Groceries);

    expect((string) $rate?->percentage)->toBe('20')
        ->and($rate?->confidence)->toBe(Confidence::LowConfidence)
        ->and($rate?->source)->toContain('unreadable');
});

it('does not quietly try the next heading when a band is unreadable', function () {
    // `book` maps to BOOKS then LOAN_LIBRARIES. If BOOKS is unreadable, falling
    // through would price the supply under a heading nobody asked about and look
    // entirely successful — the quiet substitution this source exists to avoid.
    $source = euWithEditedFr(function (array $window): array {
        $window['bands']['BOOKS'] = ['rate' => '999', 'basis' => 'source'];

        return $window;
    });

    $rate = $source->rateFor(euPlace('FR'), TaxClass::Book);

    expect($rate?->confidence)->toBe(Confidence::LowConfidence)
        ->and($rate?->source)->toContain('BOOKS')
        ->and($rate?->source)->not->toContain('LOAN_LIBRARIES');
});

it('denies when even the standard rate will not read', function () {
    // A corrupt band can be worked around by charging the standard rate. A corrupt
    // standard rate cannot: there is nothing left to fall back to, so the source
    // denies and the engine refuses rather than inventing a percentage.
    $source = euWithEditedFr(function (array $window): array {
        $window['standard'] = '-5';

        return $window;
    });

    expect($source->rateFor(euPlace('FR'), TaxClass::Electronics))->toBeNull();
});

it('survives a rates section that is not JSON at all', function () {
    expect(euCorrupt('<html>gateway timeout</html>')->rateFor(euPlace('FR'), TaxClass::Electronics))->toBeNull();
});
