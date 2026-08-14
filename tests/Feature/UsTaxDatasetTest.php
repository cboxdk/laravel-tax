<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\LocalityCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\NexusCombinator;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\SourcingMode;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Nexus\UsTaxDatasetNexus;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\Sourcing\UsTaxDatasetSourcing;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\Taxability\UsTaxDatasetTaxability;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->dataset = new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname(__DIR__).'/Fixtures/us-tax-dataset',
    );
});

function datasetPlace(string $state, ?LocalityCode $locality = null): Jurisdiction
{
    $j = test()->geo->find(new CountryCode('US'), new SubdivisionCode($state));

    return $locality === null ? $j : $j->withLocality($locality);
}

// ---- Rate source ---------------------------------------------------------

it('resolves an authoritative state rate at derived confidence for a US state', function () {
    $source = new UsTaxDatasetRateSource($this->dataset);

    $rate = $source->rateFor(datasetPlace('US-CA'), TaxClass::GeneralGoods);

    expect((string) $rate->percentage)->toBe('7.25')
        ->and($rate->kind)->toBe(RateKind::Standard)
        ->and($rate->source)->toBe('us-tax-data')
        ->and($rate->confidence)->toBe(Confidence::Derived); // state-level, not rooftop all-in
});

it('returns null for a non-US jurisdiction so a chain falls through', function () {
    $source = new UsTaxDatasetRateSource($this->dataset);

    expect($source->rateFor($this->geo->find(new CountryCode('DE')), TaxClass::GeneralGoods))->toBeNull();
});

it('applies a reduced-rate category rule over the general rate', function () {
    $source = new UsTaxDatasetRateSource($this->dataset);

    // Missouri's reduced 1.225% grocery rate is its STATE share — Missouri's own
    // guidance is explicit that local sales taxes continue to apply to food. With
    // no rooftop locality resolved this is the same partial answer the general
    // state rate is, and it now carries the same confidence rather than claiming
    // to be the whole rate.
    $rate = $source->rateFor(datasetPlace('US-MO'), TaxClass::Groceries);

    expect((string) $rate->percentage)->toBe('1.225')
        ->and($rate->kind)->toBe(RateKind::Reduced)
        ->and($rate->confidence)->toBe(Confidence::Derived);
});

it('stacks a combined local record into an all-in rooftop rate', function () {
    $source = new UsTaxDatasetRateSource($this->dataset);

    // California is combined-basis: the local record is already all-in (10.75%).
    $locality = new LocalityCode(new SubdivisionCode('US-CA'), 'ca-place', '06:ALAMEDA');
    $rate = $source->rateFor(datasetPlace('US-CA', $locality), TaxClass::GeneralGoods);

    expect((string) $rate->percentage)->toBe('10.75')
        ->and($rate->confidence)->toBe(Confidence::Authoritative); // rooftop
});

it('stacks a component local record onto the state share', function () {
    $source = new UsTaxDatasetRateSource($this->dataset);

    // North Carolina is component-basis: local 2% + state 4.75% = 6.75% all-in.
    $locality = new LocalityCode(new SubdivisionCode('US-NC'), 'county-fips', '001');
    $rate = $source->rateFor(datasetPlace('US-NC', $locality), TaxClass::GeneralGoods);

    expect((string) $rate->percentage)->toBe('6.75')
        ->and($rate->confidence)->toBe(Confidence::Authoritative);
});

it('falls back to the state rate when a locality has no matching local record', function () {
    $source = new UsTaxDatasetRateSource($this->dataset);

    $locality = new LocalityCode(new SubdivisionCode('US-NC'), 'county-fips', 'ZZZ-unknown');
    $rate = $source->rateFor(datasetPlace('US-NC', $locality), TaxClass::GeneralGoods);

    expect((string) $rate->percentage)->toBe('4.75') // NC state rate
        ->and($rate->confidence)->toBe(Confidence::Derived);
});

// ---- Taxability ----------------------------------------------------------

it('reads per-state, per-category taxability from the dataset', function () {
    $taxability = new UsTaxDatasetTaxability($this->dataset, new StaticProductTaxability);

    expect($taxability->determine(datasetPlace('US-CA'), TaxClass::Clothing, anyAmount())->isExemptFor(anyAmount()))->toBeFalse()
        ->and($taxability->determine(datasetPlace('US-CA'), TaxClass::DigitalService, anyAmount())->isExemptFor(anyAmount()))->toBeTrue()
        ->and($taxability->determine(datasetPlace('US-CA'), TaxClass::Groceries, anyAmount())->isExemptFor(anyAmount()))->toBeTrue()
        ->and($taxability->determine(datasetPlace('US-NY'), TaxClass::DigitalService, anyAmount())->isExemptFor(anyAmount()))->toBeFalse();
});

it('delegates non-US taxability to the fallback matrix', function () {
    $taxability = new UsTaxDatasetTaxability($this->dataset, new StaticProductTaxability);

    // Standard goods are taxable everywhere by the fallback default.
    expect($taxability->determine($this->geo->find(new CountryCode('DE')), TaxClass::GeneralGoods, anyAmount())->isExemptFor(anyAmount()))->toBeFalse();
});

// ---- Nexus ---------------------------------------------------------------

it('reads economic-nexus thresholds from the dataset', function () {
    $nexus = new UsTaxDatasetNexus($this->dataset);

    $tx = $nexus->for(new SubdivisionCode('US-TX'));

    expect($tx)->not->toBeNull()
        ->and($tx->salesDollars)->toBe(500_000)
        ->and($tx->transactions)->toBeNull()
        ->and($tx->combinator)->toBe(NexusCombinator::SalesOnly)
        ->and($nexus->for(new SubdivisionCode('US-DE')))->toBeNull(); // no sales tax
});

// ---- Sourcing ------------------------------------------------------------

it('reads intrastate sourcing rules from the dataset', function () {
    $sourcing = new UsTaxDatasetSourcing($this->dataset);

    $ca = $sourcing->for(new SubdivisionCode('US-CA'));
    $tx = $sourcing->for(new SubdivisionCode('US-TX'));

    expect($ca->mode)->toBe(SourcingMode::Mixed)
        ->and($ca->note)->not->toBeNull()
        ->and($tx->mode)->toBe(SourcingMode::Origin)
        ->and($tx->note)->toBeNull();
});

it('binds SourcingRules to the dataset by default', function () {
    expect($this->app->make(SourcingRules::class))->toBeInstanceOf(UsTaxDatasetSourcing::class)
        ->and($this->app->make(SourcingRules::class)->for(new SubdivisionCode('US-TX'))->mode)->toBe(SourcingMode::Origin);
});

// ---- Deny-by-default -----------------------------------------------------

it('denies (returns null) when the dataset location is unreadable', function () {
    $missing = new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        '/no/such/dataset/dir',
    );

    $source = new UsTaxDatasetRateSource($missing);

    expect($source->rateFor(datasetPlace('US-CA'), TaxClass::GeneralGoods))->toBeNull();
});

it('selects the nexus window in effect now over a future-dated one', function () {
    // A state with a current window and a pending (future-dated) threshold change.
    $dir = sys_get_temp_dir().'/us-dataset-'.bin2hex(random_bytes(5)).'/by-section';
    mkdir($dir, 0o755, true);
    file_put_contents($dir.'/nexus.json', json_encode(['states' => [
        'US-XX' => [
            ['salesUsd' => 500000, 'transactions' => null, 'combinator' => 'sales_only', 'effectiveFrom' => null, 'effectiveTo' => null],
            ['salesUsd' => 250000, 'transactions' => null, 'combinator' => 'sales_only', 'effectiveFrom' => '2099-01-01', 'effectiveTo' => null],
        ],
    ]]));

    $dataset = new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        dirname($dir),
    );

    // The open-ended current window ($500k) wins; the 2099 window is ignored today.
    expect($dataset->nexus('US-XX'))->toBe([
        'salesUsd' => 500000,
        'transactions' => null,
        'combinator' => 'sales_only',
    ]);
});

// ---- Curated notes (the "see … note" caveats) ----------------------------

it('exposes a state rate note and returns null where none is carried', function () {
    // California carries a rate note (records are combined all-in); Arkansas carries none.
    expect($this->dataset->rateNote('US-CA'))->toContain('CDTFA')
        ->and($this->dataset->rateNote('US-TX'))->toContain('Point-in-time snapshot')
        ->and($this->dataset->rateNote('US-AR'))->toBeNull()
        ->and($this->dataset->rateNote('US-ZZ'))->toBeNull(); // unknown state
});

it('exposes a baseline note (in the window in effect now) and null where none is carried', function () {
    // Delaware's baseline note explains the gross-receipts tax; Arkansas carries none.
    expect($this->dataset->baselineNote('US-DE'))->toContain('gross receipts')
        ->and($this->dataset->baselineNote('US-VA'))->toContain('mandatory statewide 1% local levy')
        ->and($this->dataset->baselineNote('US-AR'))->toBeNull()
        ->and($this->dataset->baselineNote('US-ZZ'))->toBeNull();
});

it('resolves the dataset from the container so consumers can read notes', function () {
    // The fixture location is wired by the base TestCase, so the binding resolves a real dataset.
    $resolved = $this->app->make(UsTaxDataset::class);

    expect($resolved)->toBeInstanceOf(UsTaxDataset::class)
        ->and($resolved->baselineNote('US-DE'))->toContain('gross receipts');
});

it('resolves to null when the US dataset is disabled', function () {
    config()->set('tax.us_tax_data.enabled', false);
    $this->app->forgetInstance(UsTaxDataset::class);

    expect($this->app->make(UsTaxDataset::class))->toBeNull();
});

// The boundary fixture is a slice of the real dist/boundaries/US-KS.json compiled
// from the SSTGB Kansas boundary file, and the rate records are the real Wyandotte
// County (209) and Kansas City (36000) rows.

it('sums every local record the boundary index says applies', function () {
    // 66101-6200 is a Kansas City address: county AND city both apply, so the rate
    // is 6.5% state + 1.0% county + 1.625% city. Taking either local record alone
    // would be 100 bp out.
    $locality = new LocalityCode(new SubdivisionCode('US-KS'), UsTaxDatasetRateSource::ZIP9_SCHEME, '66101-6200');

    $rate = new UsTaxDatasetRateSource($this->dataset)
        ->rateFor(datasetPlace('US-KS', $locality), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('9.125')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative)
        ->and($rate?->kind)->toBe(RateKind::Standard);
});

it('refuses a partial match rather than under-charging', function () {
    // 66101-3413 resolves to county 209 AND city 20000 (Edwardsville). The trimmed
    // fixture carries no record for 20000, so summing what is left would be short
    // by that city's share while still claiming to be authoritative. The honest
    // answer is the state rate.
    $locality = new LocalityCode(new SubdivisionCode('US-KS'), UsTaxDatasetRateSource::ZIP9_SCHEME, '66101-3413');

    $rate = new UsTaxDatasetRateSource($this->dataset)
        ->rateFor(datasetPlace('US-KS', $locality), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('6.5')
        ->and($rate?->confidence)->toBe(Confidence::Derived);
});

it('falls back to the state rate when the ZIP is not in the index at all', function () {
    $locality = new LocalityCode(new SubdivisionCode('US-KS'), UsTaxDatasetRateSource::ZIP9_SCHEME, '99999-0001');

    $rate = new UsTaxDatasetRateSource($this->dataset)
        ->rateFor(datasetPlace('US-KS', $locality), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('6.5')
        ->and($rate?->confidence)->toBe(Confidence::Derived);
});

it('still accepts a direct jurisdiction code, so a caller can supply its own', function () {
    $locality = new LocalityCode(new SubdivisionCode('US-KS'), 'sst-fips', '36000');

    $rate = new UsTaxDatasetRateSource($this->dataset)
        ->rateFor(datasetPlace('US-KS', $locality), TaxClass::GeneralGoods);

    // City only: 6.5% + 1.625%. Honest for a caller that resolved one authority,
    // and exactly why the ZIP+4 path exists — it finds the ones you did not.
    expect((string) $rate?->percentage)->toBe('8.125');
});

it('prefers the gzipped boundary index, which is how it is published', function () {
    // Both forms exist in the fixture directory; the .gz is read and inflated.
    $locality = new LocalityCode(new SubdivisionCode('US-KS'), UsTaxDatasetRateSource::ZIP9_SCHEME, '66101-6200');

    $rate = new UsTaxDatasetRateSource($this->dataset)
        ->rateFor(datasetPlace('US-KS', $locality), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('9.125');
});

it('falls back to the plain index when no gzipped one is published', function () {
    $only = dirname(__DIR__).'/Fixtures/us-tax-dataset';
    $temporary = sys_get_temp_dir().'/cbox-tax-boundaries-'.bin2hex(random_bytes(4));

    mkdir($temporary.'/boundaries', 0o755, true);
    mkdir($temporary.'/by-section', 0o755, true);

    foreach (['rates', 'baseline', 'taxability', 'nexus', 'sourcing'] as $section) {
        copy($only.'/by-section/'.$section.'.json', $temporary.'/by-section/'.$section.'.json');
    }

    copy($only.'/boundaries/US-KS.json', $temporary.'/boundaries/US-KS.json');   // no .gz sibling

    $dataset = new UsTaxDataset(
        $this->app->make(Factory::class),
        $this->app->make(Cache::class),
        $temporary,
    );

    $locality = new LocalityCode(new SubdivisionCode('US-KS'), UsTaxDatasetRateSource::ZIP9_SCHEME, '66101-6200');

    expect((string) new UsTaxDatasetRateSource($dataset)
        ->rateFor(datasetPlace('US-KS', $locality), TaxClass::GeneralGoods)?->percentage)->toBe('9.125');
});

it('treats "no local authority applies" as an authoritative answer', function () {
    // Indiana levies no local sales tax and states its whole territory in one
    // ZIP-spanning row carrying no authorities. The rate equals the state share,
    // but it is a complete all-in answer — not a fallback that might be missing
    // locals, which is what Derived would signal.
    //
    // `zip` is built as an empty OBJECT because that is what the dataset publishes
    // for these states. PHP decodes `{}` and `[]` to the same empty array, so this
    // reader cannot tell them apart — but the fixture should still be the shape
    // that actually ships.
    $index = json_decode((string) file_get_contents(
        dirname(__DIR__).'/Fixtures/us-tax-dataset/boundaries/US-KS.json'
    ), true);

    $index['sets'] = [[]];
    $index['zip'] = (object) [];
    $index['ranges'] = [['66000', '67999', '0000', '9999', 0]];

    $directory = sys_get_temp_dir().'/cbox-tax-empty-'.bin2hex(random_bytes(4));
    mkdir($directory.'/boundaries', 0o755, true);
    mkdir($directory.'/by-section', 0o755, true);

    foreach (['rates', 'baseline', 'taxability', 'nexus', 'sourcing'] as $section) {
        copy(
            dirname(__DIR__).'/Fixtures/us-tax-dataset/by-section/'.$section.'.json',
            $directory.'/by-section/'.$section.'.json',
        );
    }

    file_put_contents($directory.'/boundaries/US-KS.json', json_encode($index));

    $dataset = new UsTaxDataset($this->app->make(Factory::class), $this->app->make(Cache::class), $directory);
    $locality = new LocalityCode(new SubdivisionCode('US-KS'), UsTaxDatasetRateSource::ZIP9_SCHEME, '66101-6200');

    $rate = new UsTaxDatasetRateSource($dataset)->rateFor(datasetPlace('US-KS', $locality), TaxClass::GeneralGoods);

    expect((string) $rate?->percentage)->toBe('6.5')
        ->and($rate?->confidence)->toBe(Confidence::Authoritative);
});

// ---- The publisher's manifest is checked, not just trusted ----------------

it('refuses a remote section whose bytes do not match the manifest', function () {
    // The default location is a MUTABLE branch head on a third-party host. One bad
    // push would otherwise land in every deployment within a TTL, with no alarm.
    $http = new Factory;
    $http->fake([
        'dataset.test/manifest.json' => $http->response([
            'schemaVersion' => 4,
            'files' => ['sections' => ['baseline' => ['sha256' => str_repeat('0', 64)]]],
        ]),
        'dataset.test/by-section/baseline.json' => $http->response([
            'states' => ['US-CA' => ['baseline' => [['stateRate' => 0.99, 'noSalesTax' => false]]]],
        ]),
    ]);

    $dataset = new UsTaxDataset($http, $this->app->make(Cache::class), 'https://dataset.test');

    expect($dataset->stateRatePercent('US-CA'))->toBeNull();
});

it('accepts a remote section whose bytes match the manifest', function () {
    $body = json_encode(['states' => ['US-CA' => ['baseline' => [
        ['stateRate' => 0.0725, 'noSalesTax' => false, 'effectiveFrom' => null, 'effectiveTo' => null],
    ]]]]);

    $http = new Factory;
    $http->fake([
        'ok.test/manifest.json' => $http->response([
            'schemaVersion' => 4,
            'files' => ['sections' => ['baseline' => ['sha256' => hash('sha256', (string) $body)]]],
        ]),
        'ok.test/by-section/baseline.json' => $http->response($body, 200, ['Content-Type' => 'application/json']),
    ]);

    $dataset = new UsTaxDataset($http, $this->app->make(Cache::class), 'https://ok.test');

    expect($dataset->stateRatePercent('US-CA'))->toBe('7.25');
});

it('refuses a schema version this reader was not written for', function () {
    // A bump that re-scaled stateRate from a fraction to a percentage would be
    // multiplied by 100 again here and charge 725% at Authoritative confidence.
    $body = json_encode(['states' => ['US-CA' => ['baseline' => [['stateRate' => 7.25, 'noSalesTax' => false]]]]]);

    $http = new Factory;
    $http->fake([
        'v5.test/manifest.json' => $http->response([
            'schemaVersion' => 5,
            'files' => ['sections' => ['baseline' => ['sha256' => hash('sha256', (string) $body)]]],
        ]),
        'v5.test/by-section/baseline.json' => $http->response($body, 200, ['Content-Type' => 'application/json']),
    ]);

    $dataset = new UsTaxDataset($http, $this->app->make(Cache::class), 'https://v5.test');

    expect($dataset->stateRatePercent('US-CA'))->toBeNull();
});

it('refuses a remote source that publishes no manifest at all', function () {
    // Over the network you did not choose the bytes. A missing manifest is a fetch
    // that went somewhere unexpected, not a deliberate operator choice.
    $http = new Factory;
    $http->fake([
        'bare.test/manifest.json' => $http->response('', 404),
        'bare.test/by-section/baseline.json' => $http->response([
            'states' => ['US-CA' => ['baseline' => [['stateRate' => 0.0725, 'noSalesTax' => false]]]],
        ]),
    ]);

    $dataset = new UsTaxDataset($http, $this->app->make(Cache::class), 'https://bare.test');

    expect($dataset->stateRatePercent('US-CA'))->toBeNull();
});

it('still reads a local mirror that has no manifest', function () {
    // On your own disk you DID choose the bytes; requiring a manifest there would
    // break every committed fixture and offline build for no gain in trust.
    expect($this->dataset->stateRatePercent('US-CA'))->toBe('7.25');
});
