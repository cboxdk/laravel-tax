<?php

declare(strict_types=1);

namespace Cbox\Tax\Testing;

use Cbox\Tax\Contracts\RegisterData;
use Cbox\Tax\Exceptions\DatasetNotInstalled;
use Cbox\Tax\Exceptions\Refusal;
use Cbox\Tax\Exceptions\UnknownCategory;
use Cbox\Tax\Register\Reader\RegisterCompatibility;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\Register\Store\ShardKey;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/**
 * What every implementation of {@see RegisterData} must do, as one assertion.
 *
 * Use it in the test of a dataset of your own:
 *
 *     uses(DatasetConformance::class);
 *
 *     it('is a dataset the engine can read', function (): void {
 *         $this->assertDatasetConforms(new MyDataset(...));
 *     });
 *
 * It needs a dataset that holds a release, and checks the rules the engine relies on
 * without knowing what any rate is: the release does not move, records are filed
 * under the code they are asked by, an unknown code is an empty answer, a release
 * that is not available refuses, and every derived question agrees with the sections
 * it is derived from. It does not prove the DATA is right; for that, compare against
 * a dataset known to be ({@see DatasetComparison}).
 *
 * The package runs it against its own file store.
 */
trait DatasetConformance
{
    /**
     * @param  int|null  $sample  check at most this many codes per shard; null checks every code
     */
    protected function assertDatasetConforms(RegisterData $dataset, ?int $sample = null): void
    {
        $this->assertHoldsOneRelease($dataset);
        $this->assertSectionsAreReadable($dataset);

        $shards = $dataset->shards();
        Assert::assertNotSame([], $shards, 'A dataset with a release holds at least one shard of records.');
        Assert::assertSame(array_values(array_unique($shards)), $shards, 'shards() names each shard once.');

        $records = 0;

        foreach ($shards as $shard) {
            $codes = $dataset->codesIn($shard);
            Assert::assertSame(array_values(array_unique($codes)), $codes, "codesIn({$shard}) names each code once.");

            $names = $dataset->namesIn($shard);

            foreach ($names as $code => $name) {
                Assert::assertContains((string) $code, $codes, "namesIn({$shard}) names {$code}, which codesIn({$shard}) does not hold.");
                Assert::assertSame($name, $dataset->jurisdiction((string) $code)['name'] ?? null, "namesIn({$shard}) and jurisdiction({$code}) disagree about the name.");
            }

            foreach ($sample === null ? $codes : array_slice($codes, 0, $sample) as $code) {
                $records += $this->assertCodeIsFiledWhereItIsAsked($dataset, $shard, $code);
            }
        }

        Assert::assertGreaterThan(0, $records, 'A dataset with a release holds at least one rate or jurisdiction record.');

        $this->assertUnknownIsEmpty($dataset);
        $this->assertVocabularyAgrees($dataset);
        $this->assertRulesAgree($dataset);
        $this->assertAnotherReleaseIsAnotherInstance($dataset);
    }

    private function assertHoldsOneRelease(RegisterData $dataset): void
    {
        $version = $dataset->version();

        Assert::assertNotNull($version, 'The conformance check needs a dataset that holds a release.');
        Assert::assertTrue($dataset->isInstalled());
        Assert::assertSame($version, $dataset->requireVersion());
        Assert::assertSame($version, $dataset->version(), 'The release does not move for the life of an instance.');
        Assert::assertNotSame('', $dataset->storeRoot(), 'storeRoot() names where the dataset reads from.');

        $manifest = $dataset->manifest();

        Assert::assertSame($version, $manifest['version'] ?? null, 'The manifest names the release the instance reads.');
        Assert::assertTrue(
            RegisterCompatibility::reads($manifest['schemaVersion'] ?? null),
            'The manifest states a schema version this engine reads.',
        );
        Assert::assertSame($manifest, $dataset->document('manifest'), 'manifest() is the manifest section.');
        Assert::assertSame($dataset->coverage(), $dataset->document('coverage'), 'coverage() is the coverage section.');
    }

    private function assertSectionsAreReadable(RegisterData $dataset): void
    {
        $meta = $dataset->document('meta');

        Assert::assertNotSame([], Shape::records($meta['regimes'] ?? null), 'The meta section carries the regimes.');
        Assert::assertArrayHasKey('rules', $dataset->document('rules'), 'The rules section carries a rules list.');
        Assert::assertSame([], $dataset->document('no-such-section'), 'A section the release does not carry is an empty array.');
        Assert::assertSame($dataset->document('meta'), $meta, 'A section reads the same twice.');
    }

    /** @return int how many records the code holds */
    private function assertCodeIsFiledWhereItIsAsked(RegisterData $dataset, string $shard, string $code): int
    {
        Assert::assertSame($shard, ShardKey::of($code), "{$code} is listed in shard {$shard} but belongs to ".ShardKey::of($code).'.');
        Assert::assertTrue($dataset->carries($code), "The dataset holds records for {$code} and says it does not carry it.");

        $rates = $dataset->ratesFor($code);

        Assert::assertSame($rates, $dataset->ratesFor($code), "ratesFor({$code}) reads the same twice.");

        foreach ($rates as $rate) {
            Assert::assertSame($code, $rate['jurisdiction'] ?? null, "A rate filed under {$code} names another jurisdiction.");
            Assert::assertIsString($rate['kind'] ?? null, "A rate of {$code} has no kind.");
        }

        $record = $dataset->jurisdiction($code);

        if ($record !== null) {
            Assert::assertSame($code, $record['code'] ?? null, "The jurisdiction record of {$code} names another code.");
        }

        Assert::assertTrue($rates !== [] || $record !== null, "codesIn({$shard}) lists {$code}, which holds neither a rate nor a record.");

        foreach ($dataset->standardRates($code) as $rate) {
            Assert::assertSame($code, $rate['jurisdiction'] ?? null, "A standard rate filed under {$code} names another jurisdiction.");
        }

        return count($rates) + ($record === null ? 0 : 1);
    }

    private function assertUnknownIsEmpty(RegisterData $dataset): void
    {
        $nowhere = 'eu:__NOWHERE__';

        Assert::assertSame([], $dataset->ratesFor($nowhere), 'An unknown code has no rates; it is not an error.');
        Assert::assertNull($dataset->jurisdiction($nowhere), 'An unknown code has no jurisdiction record.');
        Assert::assertSame([], $dataset->rulesFor($nowhere));
        Assert::assertSame([], $dataset->standardRates($nowhere));
        Assert::assertSame([], $dataset->codesIn('no-such-regime'), 'An unknown shard holds no codes.');
        Assert::assertSame([], $dataset->namesIn('no-such-regime'));
        Assert::assertNull($dataset->fact('no.such.fact'));
        Assert::assertNull($dataset->usLocalResolution('ZZ'));
        Assert::assertNull($dataset->usLocalUnpriced('ZZ'));
        Assert::assertFalse($dataset->usLocalAbsenceHolds('ZZ', new DateTimeImmutable('today')));
        Assert::assertFalse($dataset->publishesGeometryFor('ZZ'));
        Assert::assertFalse($dataset->publishesOverlayFor('ZZ'));
        Assert::assertFileDoesNotExist($dataset->boundaryPath('ZZ.zip.idx'), 'A state the release holds no boundary layer for has no file.');

        $regions = $dataset->manifest()['regions'] ?? null;

        if (is_array($regions)) {
            Assert::assertFalse($dataset->carries('no-such-regime:XX'), 'A regime the manifest does not list is not carried.');
        }
    }

    private function assertVocabularyAgrees(RegisterData $dataset): void
    {
        $categories = $dataset->categories();
        $published = [];

        foreach (Shape::records($dataset->document('meta')['categories'] ?? null) as $category) {
            $key = Shape::text($category['key'] ?? null);

            if ($key !== null) {
                $published[] = $key;
            }
        }

        Assert::assertSame(array_values(array_unique($published)), array_keys($categories), 'categories() is the vocabulary, keyed by key, in published order.');

        if ($published !== []) {
            $dataset->assertCategoryPublished($published[0]);
        }

        try {
            $dataset->assertCategoryPublished('goods.__not_a_category__');
            Assert::fail('A category the release does not publish is refused.');
        } catch (UnknownCategory) {
            // The refusal the engine turns into a 422.
        }

        foreach (Shape::records($dataset->document('facts')['facts'] ?? null) as $fact) {
            $name = Shape::text($fact['fact'] ?? null);

            if ($name !== null && Shape::text($fact['question'] ?? null) !== null) {
                Assert::assertSame($name, $dataset->fact($name)?->name, "fact({$name}) is the published fact.");

                break;
            }
        }

        foreach (Shape::records($dataset->document('meta')['regimes'] ?? null) as $regime) {
            foreach (Shape::records($regime['members'] ?? null) as $member) {
                $code = Shape::text($member['code'] ?? null);

                if ($code === null || ! str_contains($code, ':')) {
                    continue;
                }

                $iso = substr($code, (int) strrpos($code, ':') + 1);

                foreach ($dataset->codesForCountry($iso) as $candidate) {
                    Assert::assertStringEndsWith(':'.$iso, $candidate, "codesForCountry({$iso}) offers a code of another country.");
                    Assert::assertContains(
                        $dataset->jurisdiction($candidate)['level'] ?? 'country',
                        ['country'],
                        "codesForCountry({$iso}) offers {$candidate}, which the register labels sub-national.",
                    );
                }

                break;
            }
        }
    }

    private function assertRulesAgree(RegisterData $dataset): void
    {
        $byJurisdiction = [];

        foreach (Shape::records($dataset->document('rules')['rules'] ?? null) as $rule) {
            $code = Shape::text($rule['jurisdiction'] ?? null);

            if ($code !== null) {
                $byJurisdiction[$code][] = $rule;
            }
        }

        foreach ($byJurisdiction as $code => $rules) {
            $byKind = [];

            foreach ($rules as $rule) {
                $byKind[Shape::scalar($rule['kind'] ?? null)][] = $rule;
            }

            foreach ($byKind as $kind => $ofKind) {
                try {
                    $answered = $dataset->rulesFor($code, $kind);
                } catch (Refusal) {
                    // A rule this engine cannot apply is refused when it is asked for,
                    // by every dataset alike; what is checked here is the reading.
                    continue;
                }

                Assert::assertSame($ofKind, $answered, "rulesFor({$code}, {$kind}) is the rules section's rules of that kind for it, in order.");

                $from = Shape::text(Shape::map($ofKind[0]['effective'] ?? null)['from'] ?? null);

                if ($from === null) {
                    continue;
                }

                Assert::assertContains(
                    $ofKind[0],
                    $dataset->rulesOn($code, $kind, new DateTimeImmutable($from)),
                    "rulesOn({$code}, {$kind}) includes a rule on the first day of its window.",
                );
                Assert::assertNotContains(
                    $ofKind[0],
                    $dataset->rulesOn($code, $kind, new DateTimeImmutable($from)->modify('-1 day')),
                    "rulesOn({$code}, {$kind}) excludes a rule the day before its window opens.",
                );
            }
        }
    }

    private function assertAnotherReleaseIsAnotherInstance(RegisterData $dataset): void
    {
        $version = $dataset->requireVersion();

        Assert::assertSame($version, $dataset->at($version)->version(), 'at() the same release reads the same release.');

        $absent = $dataset->at('0000.00.00-0');

        Assert::assertNull($absent->version(), 'A release that is not available has no version.');
        Assert::assertFalse($absent->isInstalled());
        Assert::assertSame($version, $dataset->version(), 'Asking for another release does not move this instance.');

        try {
            $absent->requireVersion();
            Assert::fail('A release that is not available refuses.');
        } catch (DatasetNotInstalled) {
            // Nothing is priced from a release that is not there.
        }
    }
}
