---
title: Your own dataset
weight: 8
description: Keep the register somewhere other than the file store — a database, a service of your own — and have the engine read it through one contract.
---

# Your own dataset

The engine reads the register through one contract, `Cbox\Tax\Contracts\RegisterData`.
The default binding is the **file store**: `tax:data:sync` compiles a release into
shard files on local disk and `Register\Reader\RegisterDataset` reads a record at a
time from them. Most applications never touch this.

Bind your own implementation when the register already lives somewhere your
application can read directly, for example a database the release is loaded into,
and you want a release to be live without compiling a store on every machine.

```php
use Cbox\Tax\Contracts\RegisterData;

$this->app->singleton(RegisterData::class, fn ($app) => new DatabaseDataset(/* … */));
```

Everything that reads the register follows the binding: the rate source, nexus,
delivery, rounding, sourcing, marketplace rules, purchaser exemptions, the boundary
resolver, the geocoder, the catalogue audit and `TaxRegister`.

The file store stays bound under its own class name, `RegisterDataset`, and the
`tax:data:*` commands keep managing it. When another dataset is bound they print one
line saying so, because what they install, verify or prune is then not what a
calculation reads.

## What an implementation must do

- **One release per instance.** `version()` is resolved once and never moves for the
  life of the object, so two lines of one order are priced by the same release.
  Another release is another instance, from `at($release)`.
- **Records in the register's own shape and order.** Rates, jurisdictions and rules
  are the published records, unfiltered, in publication order. Where a category has
  more than one candidate rate the resolver takes the first, so order is part of the
  answer.
- **Nothing found is an empty answer.** An unknown code has no rates and no
  jurisdiction record. Whether a place is covered at all is `carries()`.
- **No release is a refusal.** `requireVersion()` throws `DatasetNotInstalled`;
  nothing is priced from a guess.

## Extend `DocumentDataset`

A release is a handful of small sections (the manifest, the vocabulary, the rules,
the coverage map) beside two large sets of rows (rates and jurisdictions) and the
boundary files. `Register\Reader\DocumentDataset` answers every question that can be
derived from the small sections, exactly as the file store does, and leaves you to
say where things are:

| You implement | What it is |
| --- | --- |
| `version()`, `storeRoot()`, `at($release)` | Which release this instance reads, a locator for refusals, and the same register at another release |
| `section($name)` | One small section, whole: `manifest`, `meta`, `rules`, `standard-rates`, `coverage`, `us-local`, `facts` |
| `ratesFor($code)`, `jurisdiction($code)` | The rows of one jurisdiction |
| `shards()`, `codesIn($shard)` | What the release holds, for enumeration: a shard is a regime (`eu`) or a US state (`us/TX`) |
| `boundaryPath($file)` | Where one compiled boundary file is on local disk |

```php
use Cbox\Tax\Register\Reader\DocumentDataset;

class DatabaseDataset extends DocumentDataset
{
    public function version(): ?string { /* the release this instance was built for */ }

    public function ratesFor(string $jurisdiction): array
    {
        // Every published rate record of the jurisdiction, in publication order.
    }

    protected function section(string $name): array
    {
        // 'manifest' => ['version' => …, 'schemaVersion' => …, 'contentHash' => …, 'regions' => […], 'states' => null]
        // 'meta'     => ['regimes' => […], 'categories' => […], 'mappings' => […], 'sources' => […]]
        // …an empty array for a section the release does not carry.
    }

    // …
}
```

Sections are read once per instance and kept. A release is immutable, so anything
read for it can also be cached by release for as long as you like.

## Boundaries stay files

The ZIP, ZIP+4, street and polygon layers are read a record at a time through an
index, and that is a file format whatever your rows are kept in. `boundaryPath()`
returns where the compiled file for a state is on local disk (`TX.zip`,
`TX.zip.head.json`, `KS.street`, `CA.geo`, `CA.geo.boxes.json`, `NE.overlay.json`),
whether or not it exists; a state with no file is a state this register does not
place an address in.

If you hold the published boundary artifacts rather than a compiled store,
`Register\Compile\BoundaryCompiler` turns one artifact into those files. It is the
step `tax:data:sync` runs on what it downloads:

```php
$compiler = new BoundaryCompiler;
$compiler->postal($zipArtifact, $directory, 'TX');      // TX.zip, TX.zip.idx, TX.zip.head.json
$compiler->street($streetArtifact, $directory, 'KS');   // KS.street, KS.street.idx, KS.street.sets.json
$compiler->geometry($directory, 'CA');                  // from CA.geo.json already in $directory
```

## Prove it

Two tools ship in the `Testing` namespace.

**`DatasetConformance`** is the test every implementation must pass. It checks the
rules above without knowing what any rate is:

```php
use Cbox\Tax\Testing\DatasetConformance;

uses(DatasetConformance::class);

it('is a dataset the engine can read', function (): void {
    $this->assertDatasetConforms(new DatabaseDataset(/* … */));
});
```

**`DatasetComparison`** proves two datasets return the same answers for a release. It
walks every section, every shard, every jurisdiction's rates and record, the rules
and standard rates of each, the vocabulary, every country's candidate codes, every US
state's local claims and every boundary file:

```php
use Cbox\Tax\Testing\DatasetComparison;

$comparison = DatasetComparison::between($fileStore, $databaseDataset);

expect($comparison->identical())->toBeTrue($comparison->summary());
```

Lists are compared in order and maps key by key; scalars strictly, so `"25"` and
`25` differ. Every difference is counted, the first 200 are kept in full
(`differences()`), and `patterns()` groups all of them by where they are with codes
and indexes removed, for example `rates[*][*].provenance.snapshot`, which is the
shape a cause has. `compared()` says how much was walked.

The manifest's `compiledAt`, `files` and `streets` describe a store rather than a
release and are not compared. A street index only one side was built with is listed
under `skipped()`.

The conformance check proves a dataset behaves; it does not prove its data is right.
Run the comparison against a file store compiled from the same release for that.

## Limits

- `RegisterData`, `DocumentDataset` and the store classes follow the register's
  format and change with it. An application that only asks the register questions
  should use `Contracts\TaxRegister`, which does not.
- The contract has no write side. Loading a release into your own storage, and
  deciding which release is live, are yours.
