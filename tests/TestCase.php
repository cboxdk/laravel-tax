<?php

declare(strict_types=1);

namespace Cbox\Tax\Tests;

use Cbox\Geo\GeoServiceProvider;
use Cbox\Tax\TaxServiceProvider;
use Cbox\Tax\Testing\InteractsWithTax;
use Cbox\Tax\Tests\Fixtures\SuiteRegister;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithTax;

    private ?string $store = null;

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [GeoServiceProvider::class, TaxServiceProvider::class];
    }

    /**
     * Give every test its own register, built for the test and thrown away after.
     *
     * The engine REFUSES without one, which is the behaviour this package wants in
     * production and a wall in a test suite. So each test gets a store written to a
     * temporary directory — invented rates, no network, no state shared between
     * tests. What the fixture holds is written down in {@see SuiteRegister}.
     *
     * Anything asserting what a jurisdiction really charges belongs in the `e2e`
     * group, against the live register.
     */
    protected function defineEnvironment($app): void
    {
        $this->store = sys_get_temp_dir().'/cbox-tax-suite-'.getmypid().'-'.bin2hex(random_bytes(6));

        SuiteRegister::install($this->store);

        $app['config']->set('tax.register.store', $this->store);
    }

    protected function tearDown(): void
    {
        $store = $this->store;
        $this->store = null;

        parent::tearDown();

        if ($store !== null) {
            $this->removeDirectory($store);
        }
    }

    private function removeDirectory(string $directory): void
    {
        foreach (is_dir($directory) ? (array) scandir($directory) : [] as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
