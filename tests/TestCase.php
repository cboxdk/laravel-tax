<?php

declare(strict_types=1);

namespace Cbox\Tax\Tests;

use Cbox\Geo\GeoServiceProvider;
use Cbox\Tax\TaxServiceProvider;
use Cbox\Tax\Testing\InteractsWithTax;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithTax;

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [GeoServiceProvider::class, TaxServiceProvider::class];
    }

    /**
     * Point the US dataset at the committed fixture (its `by-section` layout) so the
     * suite exercises the dataset-backed US path deterministically and offline —
     * never the live mirror.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('tax.us_tax_data.location', dirname(__DIR__).'/tests/Fixtures/us-tax-dataset');
    }
}
